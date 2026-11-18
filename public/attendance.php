<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'attendance.manage');
$role = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$attendanceType = (string) ($_GET['type'] ?? $_POST['type'] ?? 'student');
$supportContext = active_it_support_context($user);
$supportAdministrativeAttendance = $supportContext !== null
    && it_support_write_allowed($supportContext, 'attendance.php')
    && in_array($supportContext['preview_role'], ['school_proprietor', 'school_headmaster'], true);
$readOnlySupportPreview = $supportContext !== null
    && $supportContext['access_mode'] === 'read_only';
$supportPreviewRole = (string) ($supportContext['preview_role'] ?? '');
$readOnlyClassTeacherSupport = $readOnlySupportPreview && $supportPreviewRole === 'class_teacher';
$teacherAccount = $role === 'class_teacher';
$teacherId = (int) $user['id'];
if ($attendanceType === 'staff') {
    $staffRoleAllowed = in_array($role, ['school_headmaster', 'school_proprietor'], true);
    $supportRoleAllowed = !$readOnlySupportPreview
        || in_array($supportPreviewRole, ['school_headmaster', 'school_proprietor'], true);
    if (!$staffRoleAllowed || !$supportRoleAllowed) {
        audit($teacherId, 'school_staff_attendance_access_denied', 'school_id=' . $schoolId . ';role=' . $role);
        http_response_code(403);
        exit('Staff attendance is available only to the Headmaster, Proprietor, or authorized support preview.');
    }
    if ($readOnlySupportPreview) {
        audit($teacherId, 'school_staff_attendance_support_accessed', 'school_id=' . $schoolId . ';preview_role=' . $supportPreviewRole);
    }
} elseif ($role !== 'class_teacher' && !$readOnlyClassTeacherSupport && !$supportAdministrativeAttendance) {
    audit($teacherId, 'school_student_attendance_access_denied', 'school_id=' . $schoolId . ';role=' . $role);
    http_response_code(403);
    exit('Student attendance is available only to Class Teachers assigned to the class or authorized read-only support preview.');
} elseif ($readOnlySupportPreview) {
    audit($teacherId, 'school_student_attendance_support_accessed', 'school_id=' . $schoolId . ';preview_role=' . $supportPreviewRole);
}
if ($attendanceType === 'staff') {
    redirect('staff_attendance.php');
}
$attendanceDate = trim((string) ($_GET['date'] ?? $_POST['attendance_date'] ?? date('Y-m-d')));
$parsedAttendanceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
if (!$parsedAttendanceDate || $parsedAttendanceDate->format('Y-m-d') !== $attendanceDate) {
    $attendanceDate = date('Y-m-d');
}
$month = trim((string) ($_GET['month'] ?? substr($attendanceDate, 0, 7)));
$parsedMonth = DateTimeImmutable::createFromFormat('!Y-m', $month);
if (!$parsedMonth || $parsedMonth->format('Y-m') !== $month) {
    $month = date('Y-m');
    $parsedMonth = new DateTimeImmutable($month . '-01');
}
$monthStart = $parsedMonth->modify('first day of this month');
$monthEnd = $parsedMonth->modify('last day of this month');
$today = new DateTimeImmutable('today');
if (!isset($_GET['date']) && !isset($_POST['attendance_date'])) {
    $attendanceDate = $month === $today->format('Y-m')
        ? $today->format('Y-m-d')
        : $monthStart->format('Y-m-d');
}
$daywise = (string) ($_GET['daywise'] ?? '') === '1';
$blankSheet = (string) ($_GET['blank'] ?? '') === '1';
$todayDate = $today->format('Y-m-d');
$isScheduledDate = false;

$attendanceAction = (string) ($_GET['action'] ?? '');
$attendanceTaskSelected = in_array($attendanceAction, ['register', 'daywise'], true)
    || isset($_GET['month'])
    || (string) ($_GET['daywise'] ?? '') === '1'
    || (string) ($_GET['blank'] ?? '') === '1'
    || $_SERVER['REQUEST_METHOD'] === 'POST';
if (!$attendanceTaskSelected) {
    render_header($attendanceType === 'staff' ? 'Staff attendance' : 'Student attendance');
    ?>
    <section class="panel">
        <p class="eyebrow">ATTENDANCE</p>
        <h1><?= $attendanceType === 'staff' ? 'Staff attendance' : 'Student attendance' ?></h1>
        <p class="muted">Choose a register action to continue. Access to staff or assigned-class student attendance is enforced for this school account.</p>
    </section>
    <section class="report-action-grid" aria-label="Attendance actions">
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('attendance') ?></span>
            <div><h2><?= $attendanceType === 'staff' ? 'Monthly Staff Register' : 'Monthly Student Register' ?></h2><p><?= $attendanceType === 'staff' ? 'Review staff attendance, totals, and available school days for a selected month.' : 'Review your assigned class attendance by date, with the school calendar and saved totals.' ?></p></div>
            <a class="button button-primary" href="attendance.php?<?= $attendanceType === 'staff' ? 'type=staff&amp;' : '' ?>action=register&amp;month=<?= e($month) ?>">Open Register</a>
        </article>
        <?php if ($attendanceType === 'staff'): ?>
            <article class="report-action-card">
                <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('calendar') ?></span>
                <div><h2>Record Staff Attendance</h2><p>Choose the date and enter attendance, arrival times, and any required notes.</p></div>
                <a class="button" href="attendance.php?type=staff&amp;action=daywise&amp;date=<?= e($attendanceDate) ?>&amp;daywise=1&amp;month=<?= e($month) ?>">Record Attendance</a>
            </article>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
    exit;
}

if ($attendanceType === 'staff') {
    $staffPolicy = school_staff_attendance_policy($schoolId);
    $staffTermStmt = db()->prepare(
        'SELECT id, academic_year, term_name, starts_on, ends_on
         FROM school_terms WHERE school_id = ?
         ORDER BY starts_on DESC, id DESC'
    );
    $staffTermStmt->execute([$schoolId]);
    $allStaffTerms = $staffTermStmt->fetchAll();
    $staffAcademicYears = array_values(array_unique(array_map(
        static fn (array $term): string => (string) $term['academic_year'],
        $allStaffTerms
    )));
    $requestedAcademicYear = trim((string) ($_GET['academic_year'] ?? ''));
    if ($requestedAcademicYear !== '' && in_array($requestedAcademicYear, $staffAcademicYears, true)) {
        $staffTerms = array_values(array_filter(
            $allStaffTerms,
            static fn (array $term): bool => (string) $term['academic_year'] === $requestedAcademicYear
        ));
    } else {
        $requestedAcademicYear = '';
        $staffTerms = $allStaffTerms;
    }
    $selectedStaffTerm = null;
    $requestedStaffTermId = filter_var($_GET['term_id'] ?? $_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
    foreach ($staffTerms as $term) {
        if ($requestedStaffTermId !== false && (int) $term['id'] === $requestedStaffTermId) {
            $selectedStaffTerm = $term;
            break;
        }
        if ($selectedStaffTerm === null
            && $attendanceDate >= (string) $term['starts_on']
            && $attendanceDate <= (string) $term['ends_on']) {
            $selectedStaffTerm = $term;
        }
    }
    if ($selectedStaffTerm === null && $staffTerms !== []) {
        $selectedStaffTerm = $staffTerms[0];
    }
    if ($selectedStaffTerm !== null) {
        $requestedAcademicYear = (string) $selectedStaffTerm['academic_year'];
        $firstTermMonth = substr((string) $selectedStaffTerm['starts_on'], 0, 7);
        $lastTermMonth = substr((string) $selectedStaffTerm['ends_on'], 0, 7);
        if ($month < $firstTermMonth || $month > $lastTermMonth) {
            $month = $firstTermMonth;
            $parsedMonth = new DateTimeImmutable($month . '-01');
            $monthStart = $parsedMonth->modify('first day of this month');
            $monthEnd = $parsedMonth->modify('last day of this month');
            if (!$daywise) {
                $attendanceDate = max($month . '-01', (string) $selectedStaffTerm['starts_on']);
            }
        }
        if (!$daywise && !isset($_GET['date']) && !isset($_POST['attendance_date'])
            && $attendanceDate < (string) $selectedStaffTerm['starts_on']) {
            $attendanceDate = (string) $selectedStaffTerm['starts_on'];
        }
    }
    $staffTermQuery = $selectedStaffTerm === null ? '' : '&term_id=' . (int) $selectedStaffTerm['id'];
    $isScheduledDate = in_array(
        $attendanceDate,
        school_attendance_scheduled_dates(
            $schoolId,
            ['selected' => ['start' => $attendanceDate, 'end' => $attendanceDate]],
            $today
        )['selected'] ?? [],
        true
    ) && (int) (DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate)?->format('N') ?? 7) <= 5
        && ($selectedStaffTerm === null
        || ($attendanceDate >= (string) $selectedStaffTerm['starts_on']
            && $attendanceDate <= (string) $selectedStaffTerm['ends_on']));
    $staffStmt = db()->prepare(
        'SELECT ss.id, ss.employee_number, ss.role_name, u.full_name
         FROM school_staff ss
         JOIN users u ON u.id = ss.user_id AND u.status = "active"
         WHERE ss.school_id = ? AND ss.status = "active"
           AND ss.role_name NOT IN ("school_parent", "school_student")
           AND EXISTS (
               SELECT 1 FROM school_memberships sm
               WHERE sm.school_id = ss.school_id AND sm.user_id = ss.user_id AND sm.status = "active"
           )
         ORDER BY u.full_name'
    );
    $staffStmt->execute([$schoolId]);
    $allStaff = $staffStmt->fetchAll();
    $staffGroups = array_values(array_unique(array_map(
        static fn (array $member): string => (string) $member['role_name'],
        $allStaff
    )));
    sort($staffGroups, SORT_NATURAL | SORT_FLAG_CASE);
    $requestedStaffGroup = trim((string) ($_GET['staff_group'] ?? $_POST['staff_group'] ?? ''));
    if ($requestedStaffGroup !== '' && in_array($requestedStaffGroup, $staffGroups, true)) {
        $staff = array_values(array_filter(
            $allStaff,
            static fn (array $member): bool => (string) $member['role_name'] === $requestedStaffGroup
        ));
    } else {
        $requestedStaffGroup = '';
        $staff = $allStaff;
    }
    $staffGroupQuery = $requestedStaffGroup === '' ? '' : '&staff_group=' . rawurlencode($requestedStaffGroup);
    $staffContextQuery = $staffTermQuery . $staffGroupQuery;
    $staffById = [];
    foreach ($staff as $member) {
        $staffById[(int) $member['id']] = $member;
    }
    $staffDailyRecords = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && (string) ($_POST['action'] ?? '') === 'save_staff_attendance_policy') {
        require_csrf();
        if ($readOnlySupportPreview) {
            http_response_code(403);
            exit('IT Support Mode is read-only.');
        }
        $reportingTime = trim((string) ($_POST['reporting_time'] ?? ''));
        $graceMinutes = filter_var($_POST['late_grace_minutes'] ?? null, FILTER_VALIDATE_INT);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $reportingTime) !== 1
            || $graceMinutes === false || $graceMinutes < 0 || $graceMinutes > 180) {
            flash('error', 'Enter a valid reporting time and a grace period between 0 and 180 minutes.');
            redirect('attendance.php?type=staff&month=' . rawurlencode($month) . $staffContextQuery);
        }
        $settingStmt = db()->prepare(
            'INSERT INTO school_settings (school_id, setting_key, setting_value, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()'
        );
        $settingStmt->execute([$schoolId, 'staff_reporting_time', $reportingTime, $teacherId]);
        $settingStmt->execute([$schoolId, 'staff_late_grace_minutes', (string) $graceMinutes, $teacherId]);
        audit($teacherId, 'school_staff_attendance_policy_changed', 'school_id=' . $schoolId . ';reporting_time=' . $reportingTime . ';grace_minutes=' . $graceMinutes);
        flash('success', 'Staff reporting-time policy saved.');
        redirect('attendance.php?type=staff&month=' . rawurlencode($month) . $staffContextQuery);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        if ($readOnlySupportPreview) {
            http_response_code(403);
            exit('IT Support Mode is read-only.');
        }
        $submitted = $_POST['staff'] ?? [];
        if ($attendanceDate > $todayDate || !$isScheduledDate) {
            flash('error', 'Staff attendance can only be recorded for a past or current configured school day.');
            redirect('attendance.php?type=staff&month=' . rawurlencode($month) . $staffContextQuery
                . '&week_start=' . rawurlencode((string) ($_GET['week_start'] ?? ''))
                . '&date=' . rawurlencode($attendanceDate) . '&daywise=1');
        }
        if (!is_array($submitted) || $submitted === []) {
            flash('error', 'Choose at least one staff attendance entry to save.');
            redirect('attendance.php?type=staff&month=' . rawurlencode($month) . $staffContextQuery
                . '&week_start=' . rawurlencode((string) ($_GET['week_start'] ?? ''))
                . '&date=' . rawurlencode($attendanceDate) . '&daywise=1');
        }
        $pdo = db();
        $pdo->beginTransaction();
        $changed = [];
        $savedCount = 0;
        try {
            $existingStmt = $pdo->prepare(
                'SELECT status, arrival_at, expected_reporting_time, remarks FROM school_staff_attendance
                 WHERE school_id = ? AND staff_id = ? AND attendance_date = ? LIMIT 1'
            );
            $attendanceColumns = array_column(
                $pdo->query('SHOW COLUMNS FROM school_staff_attendance')->fetchAll(PDO::FETCH_ASSOC),
                'Field'
            );
            $saveColumns = [
                'school_id',
                'staff_id',
                'attendance_date',
                'arrival_at',
                'expected_reporting_time',
                'status',
                'remarks',
                'recorded_by',
                'recorded_at',
            ];
            $saveValues = ['?', '?', '?', '?', '?', '?', '?', '?', 'NOW()'];
            $saveParameters = [
                $schoolId,
                null,
                $attendanceDate,
                null,
                $staffPolicy['reporting_time'],
                null,
                null,
                $teacherId,
            ];
            foreach (['created_by', 'updated_by'] as $auditColumn) {
                if (in_array($auditColumn, $attendanceColumns, true)) {
                    $saveColumns[] = $auditColumn;
                    $saveValues[] = '?';
                    $saveParameters[] = $teacherId;
                }
            }
            $duplicateUpdates = [
                'arrival_at = VALUES(arrival_at)',
                'expected_reporting_time = VALUES(expected_reporting_time)',
                'status = VALUES(status)',
                'remarks = VALUES(remarks)',
                'recorded_by = VALUES(recorded_by)',
                'recorded_at = NOW()',
            ];
            if (in_array('updated_by', $attendanceColumns, true)) {
                $duplicateUpdates[] = 'updated_by = VALUES(updated_by)';
            }
            $saveStmt = $pdo->prepare(
                'INSERT INTO school_staff_attendance (' . implode(', ', $saveColumns) . ')
                 VALUES (' . implode(', ', $saveValues) . ')
                 ON DUPLICATE KEY UPDATE ' . implode(', ', $duplicateUpdates)
            );
            foreach ($submitted as $staffKey => $entry) {
                $staffId = filter_var($staffKey, FILTER_VALIDATE_INT);
                if ($staffId === false || !isset($staffById[$staffId]) || !is_array($entry)) {
                    throw new RuntimeException('The submitted staff member is not active in this school.');
                }
                $status = trim((string) ($entry['status'] ?? ''));
                $remarks = trim((string) ($entry['remarks'] ?? ''));
                $arrivalTime = trim((string) ($entry['arrival_time'] ?? ''));
                if ($status === '') {
                    if ($remarks !== '' || $arrivalTime !== '') {
                        throw new RuntimeException('Choose a status before entering an arrival time or note.');
                    }
                    continue;
                }
                if (!in_array($status, ['present', 'absent', 'excused', 'late'], true)) {
                    throw new RuntimeException('Choose Present, Absent, Excused, or Late for each submitted staff member.');
                }
                if (strlen($remarks) > 255) {
                    throw new RuntimeException('Staff attendance notes must be 255 characters or fewer.');
                }
                if ($status === 'excused' && $remarks === '') {
                    throw new RuntimeException('An explanation is required when approving Excused staff attendance.');
                }
                $arrivalDateTime = null;
                if ($arrivalTime !== '') {
                    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $arrivalTime) !== 1) {
                        throw new RuntimeException('Enter a valid staff arrival time.');
                    }
                    $arrivalDateTime = $attendanceDate . ' ' . $arrivalTime . ':00';
                    $arrivalAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $arrivalDateTime);
                    $reportingAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $attendanceDate . ' ' . $staffPolicy['reporting_time']);
                    if (!$arrivalAt || !$reportingAt) {
                        throw new RuntimeException('The arrival or reporting time could not be interpreted.');
                    }
                    if (in_array($status, ['present', 'late'], true)) {
                        $status = $arrivalAt > $reportingAt->modify('+' . (int) $staffPolicy['late_grace_minutes'] . ' minutes')
                            ? 'late'
                            : 'present';
                    }
                } elseif ($status === 'late') {
                    throw new RuntimeException('Record the arrival time for a Late entry.');
                }
                $existingStmt->execute([$schoolId, $staffId, $attendanceDate]);
                $oldRecord = $existingStmt->fetch() ?: null;
                $oldStatus = $oldRecord ? (string) $oldRecord['status'] : 'unmarked';
                $oldArrival = $oldRecord ? (string) ($oldRecord['arrival_at'] ?? '') : '';
                $oldReportingTime = $oldRecord ? (string) ($oldRecord['expected_reporting_time'] ?? '') : '';
                $previousRemarks = $oldRecord ? (string) ($oldRecord['remarks'] ?? '') : '';
                if ($oldStatus !== $status || $oldArrival !== (string) ($arrivalDateTime ?? '')
                    || $oldReportingTime !== $staffPolicy['reporting_time'] || $previousRemarks !== $remarks) {
                    $changed[] = 'staff=' . $staffId . ';' . $oldStatus . '>' . $status
                        . ';arrival=' . ($oldArrival ?: 'none') . '>' . ($arrivalDateTime ?: 'none')
                        . ';expected_reporting_time=' . ($oldReportingTime ?: 'unknown') . '>' . $staffPolicy['reporting_time']
                        . ';note=' . ($previousRemarks !== '' ? $previousRemarks : 'none')
                        . '>' . ($remarks !== '' ? $remarks : 'none');
                }
                $saveParameters[1] = $staffId;
                $saveParameters[3] = $arrivalDateTime;
                $saveParameters[5] = $status;
                $saveParameters[6] = $remarks !== '' ? $remarks : null;
                $saveStmt->execute($saveParameters);
                $savedCount++;
            }
            if ($savedCount === 0) {
                throw new RuntimeException('Choose at least one staff attendance status to save.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
            } else {
                error_log('Staff attendance save failed: ' . $exception->getMessage());
                flash('error', 'Staff attendance could not be saved. No entries from this submission were applied.');
            }
            redirect('attendance.php?type=staff&month=' . rawurlencode($month) . $staffContextQuery
                . '&week_start=' . rawurlencode((string) ($_GET['week_start'] ?? ''))
                . '&date=' . rawurlencode($attendanceDate) . '&daywise=1');
        }
        foreach ($changed as $change) {
            audit($teacherId, 'school_staff_attendance_changed', 'school_id=' . $schoolId . ';date=' . $attendanceDate . ';' . $change);
        }
        flash('success', 'Staff attendance saved for ' . $attendanceDate . '.');
        redirect('attendance.php?type=staff&month=' . rawurlencode($month) . $staffContextQuery
            . '&week_start=' . rawurlencode((string) ($_GET['week_start'] ?? ''))
            . '&date=' . rawurlencode($attendanceDate) . '&daywise=1');
    }

    $staffAcademicYearStmt = db()->prepare(
        'SELECT academic_year FROM school_terms
         WHERE school_id = ? AND starts_on <= ? AND ends_on >= ?
         ORDER BY starts_on LIMIT 1'
    );
    $staffAcademicYearStmt->execute([$schoolId, $monthEnd->format('Y-m-d'), $monthStart->format('Y-m-d')]);
    $staffAcademicYearLabel = $staffAcademicYearStmt->fetchColumn();
    $staffYearStartStmt = db()->prepare(
        'SELECT MIN(starts_on) FROM school_terms WHERE school_id = ? AND academic_year = ?'
    );
    $staffYearStartStmt->execute([$schoolId, $staffAcademicYearLabel === false ? '' : (string) $staffAcademicYearLabel]);
    $termYearStart = $staffYearStartStmt->fetchColumn();
    $staffDaywise = (string) ($_GET['daywise'] ?? '') === '1';
    $staffMonthCutoff = min(
        $staffDaywise ? $attendanceDate : $monthEnd->format('Y-m-d'),
        $todayDate
    );
    $staffAcademicYearStart = $selectedStaffTerm !== null
        ? (string) $selectedStaffTerm['starts_on']
        : (is_string($termYearStart) && $termYearStart !== ''
        ? $termYearStart
        : $monthStart->format('Y-m-d'));
    $staffSummaryEnd = $staffDaywise ? $attendanceDate : $monthEnd->format('Y-m-d');
    $staffCumulativeEnd = min(
        $staffSummaryEnd,
        $todayDate,
        $selectedStaffTerm === null ? $staffSummaryEnd : (string) $selectedStaffTerm['ends_on']
    );
    $staffMonthSchedule = school_attendance_scheduled_dates($schoolId, [
        'month' => ['start' => $monthStart->format('Y-m-d'), 'end' => $monthEnd->format('Y-m-d')],
    ], $monthEnd);
    $staffCumulativeSchedule = school_attendance_scheduled_dates($schoolId, [
        'year' => ['start' => $staffAcademicYearStart, 'end' => min($staffSummaryEnd, $todayDate)],
    ], $today);
    $staffMonthDays = array_fill_keys($staffMonthSchedule['month'] ?? [], true);
    $staffCumulativeDays = array_fill_keys($staffCumulativeSchedule['year'] ?? [], true);
    $staffFirstRegisterWeek = $monthStart->modify('monday this week');
    $staffLastRegisterWeekEnd = $monthEnd->modify('sunday this week');
    $staffWeeklyTermStart = $selectedStaffTerm !== null
        ? (string) $selectedStaffTerm['starts_on']
        : $staffAcademicYearStart;
    $staffWeeklyTermEnd = $selectedStaffTerm !== null
        ? (string) $selectedStaffTerm['ends_on']
        : $staffLastRegisterWeekEnd->format('Y-m-d');
    $staffWeeklyCalendarStart = max(
        $staffWeeklyTermStart,
        $staffFirstRegisterWeek->format('Y-m-d')
    );
    $staffWeeklyCalendarEnd = min(
        $staffWeeklyTermEnd,
        $staffLastRegisterWeekEnd->format('Y-m-d')
    );
    $staffWeeklySchedule = school_attendance_scheduled_dates($schoolId, [
        'register_weeks' => [
            'start' => $staffWeeklyCalendarStart,
            'end' => $staffWeeklyCalendarEnd,
        ],
    ], $today);
    $staffWeeklyDays = array_fill_keys($staffWeeklySchedule['register_weeks'] ?? [], true);
    foreach (['staffMonthDays', 'staffCumulativeDays', 'staffWeeklyDays'] as $scheduleVariable) {
        $scheduledDays = $$scheduleVariable;
        foreach (array_keys($scheduledDays) as $scheduledDate) {
            $weekdayNumber = (int) (new DateTimeImmutable($scheduledDate))->format('N');
            if ($weekdayNumber >= 6
                || $scheduledDate < $staffWeeklyTermStart
                || $scheduledDate > $staffWeeklyTermEnd) {
                unset($scheduledDays[$scheduledDate]);
            }
        }
        $$scheduleVariable = $scheduledDays;
    }
    $staffWeeklyReportingDate = $staffDaywise
        ? min($attendanceDate, $todayDate, $staffWeeklyTermEnd)
        : min($todayDate, $staffWeeklyTermEnd);
    $staffWeeklyAttendanceEnd = min($todayDate, $staffWeeklyCalendarEnd);
    $staffAttendanceRangeEnd = max($monthEnd->format('Y-m-d'), $staffWeeklyAttendanceEnd);
    $staffAttendanceRangeStart = min($staffAcademicYearStart, $staffWeeklyCalendarStart);
    $staffAttendanceStmt = db()->prepare(
        'SELECT staff_id, attendance_date, arrival_at, expected_reporting_time, status, remarks, recorded_by, recorded_at
         FROM school_staff_attendance
         WHERE school_id = ? AND attendance_date BETWEEN ? AND ?
         ORDER BY attendance_date, staff_id'
    );
    $staffAttendanceStmt->execute([$schoolId, $staffAttendanceRangeStart, $staffAttendanceRangeEnd]);
    $staffRecords = [];
    $staffMonthCounts = ['present' => 0, 'absent' => 0, 'excused' => 0, 'late' => 0];
    $staffSelectedMonthCounts = ['present' => 0, 'absent' => 0, 'excused' => 0, 'late' => 0];
    foreach ($staffAttendanceStmt->fetchAll() as $record) {
        $staffId = (int) $record['staff_id'];
        $date = (string) $record['attendance_date'];
        $staffRecords[$staffId][$date] = $record;
        $status = (string) $record['status'];
        if (isset($staffById[$staffId]) && isset($staffCumulativeDays[$date]) && $date <= $todayDate
            && in_array($status, ['present', 'absent', 'excused', 'late'], true)) {
            $staffMonthCounts[$status]++;
        }
        if (isset($staffById[$staffId]) && isset($staffMonthDays[$date]) && $date <= $staffMonthCutoff
            && in_array($status, ['present', 'absent', 'excused', 'late'], true)) {
            $staffSelectedMonthCounts[$status]++;
        }
    }
    $staffCumulativeSchoolDays = count($staffCumulativeDays);
    $staffExpectedRecords = count($staff) * count(array_filter(
        array_keys($staffCumulativeDays),
        static fn (string $date): bool => $date <= $todayDate
    ));
    $staffRecordedRecords = array_sum($staffMonthCounts);
    $staffCumulativePresent = $staffMonthCounts['present'] + $staffMonthCounts['late'];
    $staffPercentageDenominator = $staffExpectedRecords - $staffMonthCounts['excused'];
    $staffCumulativePercentage = $staffExpectedRecords > 0 && $staffRecordedRecords >= $staffExpectedRecords
        && $staffPercentageDenominator > 0
        ? round(($staffCumulativePresent / $staffPercentageDenominator) * 100, 2)
        : null;
    $staffMonthlyExpectedRecords = count($staff) * count(array_filter(
        array_keys($staffMonthDays),
        static fn (string $date): bool => $date <= $staffMonthCutoff
    ));
    $staffMonthlyIncomplete = array_sum($staffSelectedMonthCounts) < $staffMonthlyExpectedRecords;
    $staffMonthNotStarted = $monthStart->format('Y-m') > $today->format('Y-m');
    $staffMonthSummaryCaption = $staffDaywise ? 'up to selected date' : 'month to date';
    $staffBlankSheet = (string) ($_GET['blank'] ?? '') === '1';
    $previousMonth = $monthStart->modify('-1 month');
    $nextMonth = $monthStart->modify('+1 month');
    $canNavigatePrevious = $selectedStaffTerm === null
        || $previousMonth->modify('last day of this month')->format('Y-m-d') >= (string) $selectedStaffTerm['starts_on'];
    $canNavigateNext = $selectedStaffTerm === null
        || $nextMonth->format('Y-m-d') <= (string) $selectedStaffTerm['ends_on'];
    $staffMonthMin = $selectedStaffTerm === null ? '' : substr((string) $selectedStaffTerm['starts_on'], 0, 7);
    $staffMonthMax = $selectedStaffTerm === null ? '' : substr((string) $selectedStaffTerm['ends_on'], 0, 7);
    $staffDateMin = $selectedStaffTerm === null || (string) $selectedStaffTerm['starts_on'] > $todayDate
        ? ''
        : (string) $selectedStaffTerm['starts_on'];
    $staffDateMax = $selectedStaffTerm === null
        ? $todayDate
        : min($todayDate, (string) $selectedStaffTerm['ends_on']);
    $staffMonthUrl = static fn (DateTimeImmutable $targetMonth): string => 'attendance.php?type=staff&month='
        . rawurlencode($targetMonth->format('Y-m'))
        . ($selectedStaffTerm ? '&term_id=' . (int) $selectedStaffTerm['id'] : '')
        . '&academic_year=' . rawurlencode($requestedAcademicYear)
        . $staffGroupQuery;
    $staffRegisterWeeks = [];
    for ($weekStart = $staffFirstRegisterWeek; $weekStart <= $monthEnd; $weekStart = $weekStart->modify('+7 days')) {
        $staffRegisterWeeks[$weekStart->format('Y-m-d')] = [
            'start' => $weekStart,
            'end' => $weekStart->modify('+6 days'),
        ];
    }
    $staffWeekReferenceDate = $staffDaywise
        ? $attendanceDate
        : ($monthStart <= $today && $monthEnd >= $today
            ? $todayDate
            : ($monthStart > $today ? $monthStart->format('Y-m-d') : $monthEnd->format('Y-m-d')));
    $selectedStaffRegisterWeek = (new DateTimeImmutable($staffWeekReferenceDate))
        ->modify('monday this week')
        ->format('Y-m-d');
    $requestedStaffRegisterWeek = trim((string) ($_GET['week_start'] ?? ''));
    if (isset($staffRegisterWeeks[$requestedStaffRegisterWeek])) {
        $selectedStaffRegisterWeek = $requestedStaffRegisterWeek;
    }
    $staffRegisterWeekDates = [];
    foreach ($staffRegisterWeeks as $weekStartKey => $week) {
        $weekStartDate = $week['start']->format('Y-m-d');
        $weekEndDate = $week['end']->format('Y-m-d');
        $weekCutoff = min($weekEndDate, $staffWeeklyReportingDate);
        $staffRegisterWeekDates[$weekStartKey] = array_values(array_filter(
            array_keys($staffWeeklyDays),
            static fn (string $date): bool => $date >= $weekStartDate
                && $date <= $weekEndDate
                && $date <= $weekCutoff
        ));
    }
    $selectedStaffWeekSchoolDays = count($staffRegisterWeekDates[$selectedStaffRegisterWeek] ?? []);
    $selectedStaffWeekClassPresent = 0;
    $staffRegisterWeekSummaries = [];
    foreach ($staff as $member) {
        $staffId = (int) $member['id'];
        foreach ($staffRegisterWeeks as $weekStartKey => $_week) {
            $staffRegisterWeekSummaries[$staffId][$weekStartKey] = school_attendance_count_period(
                $staffRegisterWeekDates[$weekStartKey] ?? [],
                $staffRecords[$staffId] ?? [],
                true
            );
            if ($weekStartKey === $selectedStaffRegisterWeek) {
                $selectedStaffWeekClassPresent += $staffRegisterWeekSummaries[$staffId][$weekStartKey]['attended'];
            }
        }
    }

    if ($staffDaywise) {
        $dailyStmt = db()->prepare(
            'SELECT staff_id, arrival_at, expected_reporting_time, status, remarks, recorded_at
             FROM school_staff_attendance
             WHERE school_id = ? AND attendance_date = ?'
        );
        $dailyStmt->execute([$schoolId, $attendanceDate]);
        foreach ($dailyStmt->fetchAll() as $dailyRecord) {
            $staffDailyRecords[(int) $dailyRecord['staff_id']] = $dailyRecord;
        }
    }
    render_header('Monthly staff attendance');
    ?>
    <p class="action-task-toolbar"><a class="button button-quiet" href="attendance.php?type=staff">Back to Actions</a></p>
    <section class="panel attendance-page-heading">
        <p class="eyebrow">STAFF ATTENDANCE</p><h1>Monthly staff attendance register</h1>
        <p class="muted">Expected reporting time: <?= e($staffPolicy['reporting_time']) ?><?= (int) $staffPolicy['late_grace_minutes'] > 0 ? ' · grace ' . (int) $staffPolicy['late_grace_minutes'] . ' minutes' : '' ?>. Attendance is recorded for configured school days only.</p>
    </section>
    <section class="attendance-summary-cards" aria-label="Staff attendance summary for <?= e($month) ?>">
        <article class="attendance-summary-card attendance-card-total"><strong><?= count($staffMonthDays) ?></strong><span>Monthly School Days</span></article>
        <article class="attendance-summary-card attendance-card-count-present"><strong><?= (int) ($staffSelectedMonthCounts['present'] + $staffSelectedMonthCounts['late']) ?></strong><span>Monthly Days Present · <?= e($staffMonthSummaryCaption) ?>, includes Late<?= $staffMonthNotStarted ? ' · Not started' : ($staffMonthlyIncomplete ? ' · Provisional' : '') ?></span></article>
        <article class="attendance-summary-card attendance-card-total"><strong><?= $staffCumulativeSchoolDays ?></strong><span>Cumulative School Days</span></article>
        <article class="attendance-summary-card attendance-card-present"><strong><?= (int) $staffCumulativePresent ?></strong><span>Cumulative Days Present · includes Late<?= $staffCumulativePercentage === null ? ' · Provisional' : ' · ' . number_format($staffCumulativePercentage, 2) . '%' ?></span></article>
        <article class="attendance-summary-card attendance-card-total"><strong><?= $selectedStaffWeekSchoolDays ?></strong><span>Number of School Days · selected week</span></article>
        <article class="attendance-summary-card attendance-card-count-present"><strong><?= $selectedStaffWeekClassPresent ?></strong><span>Staff Days Present · staff-days, includes Late; each member's total is shown in their weekly row</span></article>
    </section>
    <section class="panel spaced-top" aria-label="Staff attendance by status">
        <h2>Recorded staff attendance by status</h2>
        <p class="muted">Recorded staff-days for the selected month through <?= e($staffMonthSummaryCaption) ?><?= $requestedStaffGroup !== '' ? ' · ' . e(ucwords(str_replace('_', ' ', $requestedStaffGroup))) : '' ?>. Blank entries are not included.</p>
        <?php if (array_sum($staffSelectedMonthCounts) > 0): ?>
            <?php render_dashboard_bar_chart('Recorded staff-days by status', array_map('intval', $staffSelectedMonthCounts), 'staff-days'); ?>
        <?php else: ?>
            <p class="muted">No staff attendance has been recorded for this reporting period.</p>
        <?php endif; ?>
    </section>
    <section class="panel attendance-month-actions">
        <details class="attendance-strength"><summary class="button">Staff Strength</summary><p><strong><?= count($staff) ?></strong> active staff members in this school.</p></details>
        <form method="get" action="attendance.php" class="attendance-day-picker">
            <input type="hidden" name="type" value="staff"><input type="hidden" name="month" value="<?= e($month) ?>">
            <?php if ($selectedStaffTerm): ?><input type="hidden" name="term_id" value="<?= (int) $selectedStaffTerm['id'] ?>"><?php endif; ?>
            <?php if ($requestedAcademicYear !== ''): ?><input type="hidden" name="academic_year" value="<?= e($requestedAcademicYear) ?>"><?php endif; ?>
            <?php if ($requestedStaffGroup !== ''): ?><input type="hidden" name="staff_group" value="<?= e($requestedStaffGroup) ?>"><?php endif; ?>
            <label for="staff-daywise-date">Day-Wise Attendance date</label><input id="staff-daywise-date" name="date" type="date" value="<?= e($attendanceDate) ?>" <?= $staffDateMin !== '' ? 'min="' . e($staffDateMin) . '"' : '' ?> max="<?= e($staffDateMax) ?>" required>
            <input type="hidden" name="daywise" value="1"><button class="button" type="submit">Day-Wise Attendance</button>
        </form>
        <a class="button" href="attendance.php?type=staff&amp;month=<?= e($month) ?><?= e($staffContextQuery) ?>&amp;blank=1">Blank Attendance Sheet</a>
        <button class="button button-primary" type="button" data-print-button>Print</button>
    </section>
    <section class="panel attendance-filter-panel">
        <form method="get" action="attendance.php" class="attendance-month-filters attendance-staff-month-filters">
            <input type="hidden" name="type" value="staff">
            <label for="staff-academic-year">Academic year
                <select id="staff-academic-year" name="academic_year" onchange="this.form.submit()">
                    <?php foreach ($staffAcademicYears as $academicYear): ?><option value="<?= e($academicYear) ?>" <?= $requestedAcademicYear === $academicYear ? 'selected' : '' ?>><?= e(school_academic_year_label($academicYear)) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label for="staff-attendance-term">Academic term
                <select id="staff-attendance-term" name="term_id" <?= $staffTerms === [] ? 'disabled' : '' ?>>
                    <?php foreach ($staffTerms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $selectedStaffTerm && (int) $selectedStaffTerm['id'] === (int) $term['id'] ? 'selected' : '' ?>><?= e((string) $term['term_name'] . ' · ' . (string) $term['starts_on'] . ' to ' . (string) $term['ends_on']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label for="staff-group">Staff group
                <select id="staff-group" name="staff_group">
                    <option value="">All active staff</option>
                    <?php foreach ($staffGroups as $staffGroup): ?><option value="<?= e($staffGroup) ?>" <?= $requestedStaffGroup === $staffGroup ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $staffGroup))) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label for="staff-attendance-month">Month<input id="staff-attendance-month" type="month" name="month" value="<?= e($month) ?>" <?= $staffMonthMin !== '' ? 'min="' . e($staffMonthMin) . '"' : '' ?> <?= $staffMonthMax !== '' ? 'max="' . e($staffMonthMax) . '"' : '' ?> required></label>
            <label for="staff-attendance-week">Weekly summary period
                <select id="staff-attendance-week" name="week_start">
                    <?php foreach ($staffRegisterWeeks as $weekStartKey => $week): ?>
                        <option value="<?= e($weekStartKey) ?>" <?= $selectedStaffRegisterWeek === $weekStartKey ? 'selected' : '' ?>><?= e($week['start']->format('j M')) ?> – <?= e($week['end']->format('j M Y')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button button-primary" type="submit">Load Register</button>
        </form>
    </section>
    <?php if (!$readOnlySupportPreview): ?>
        <details class="panel attendance-policy-panel" id="staff-policy">
            <summary>Configure staff reporting time</summary>
            <form method="post" action="attendance.php?type=staff&amp;month=<?= e($month) ?><?= e($staffContextQuery) ?>" class="attendance-policy-form">
                <?= csrf_field() ?><input type="hidden" name="type" value="staff"><input type="hidden" name="action" value="save_staff_attendance_policy">
                <label for="staff-reporting-time">Expected reporting time<input id="staff-reporting-time" type="time" name="reporting_time" value="<?= e($staffPolicy['reporting_time']) ?>" required></label>
                <label for="staff-late-grace">Late grace period, minutes<input id="staff-late-grace" type="number" name="late_grace_minutes" min="0" max="180" value="<?= (int) $staffPolicy['late_grace_minutes'] ?>" required></label>
                <button class="button button-primary" type="submit">Save Staff Policy</button>
            </form>
        </details>
    <?php endif; ?>
    <section class="panel attendance-month-panel" id="attendance-monthly-register">
        <div class="attendance-register-heading"><div><h2><?= $requestedStaffGroup === '' ? 'All active school staff' : e(ucwords(str_replace('_', ' ', $requestedStaffGroup))) ?></h2><p class="muted"><?= e($monthStart->format('F Y')) ?><?= $selectedStaffTerm ? ' · ' . e((string) $selectedStaffTerm['academic_year'] . ' · ' . $selectedStaffTerm['term_name']) : '' ?> · <?= count($staff) ?> staff · <?= $staffCumulativeSchoolDays ?> cumulative school days</p></div><?php if ($staffBlankSheet): ?><strong class="attendance-blank-label">Blank register for printing</strong><?php endif; ?></div>
        <nav class="attendance-month-navigation" aria-label="Staff attendance month navigation">
            <?php if ($canNavigatePrevious): ?><a class="button button-quiet" href="<?= e($staffMonthUrl($previousMonth)) ?>">Previous Month</a><?php else: ?><span class="button button-quiet" aria-disabled="true">Previous Month</span><?php endif; ?>
            <strong><?= e($monthStart->format('F Y')) ?></strong>
            <?php if ($canNavigateNext): ?><a class="button button-quiet" href="<?= e($staffMonthUrl($nextMonth)) ?>">Next Month</a><?php else: ?><span class="button button-quiet" aria-disabled="true">Next Month</span><?php endif; ?>
        </nav>
        <?php if ($readOnlySupportPreview): ?><p class="notice">Support preview is read-only.</p><?php endif; ?>
        <?php if ($staff === []): ?><p class="muted">No active staff members are available for attendance.</p><?php else: ?>
        <div class="attendance-month-scroll"><table class="attendance-month-table attendance-staff-month-table">
            <thead>
                <tr>
                    <th class="attendance-sticky attendance-sticky-roll" rowspan="2">Staff No.</th>
                    <th class="attendance-sticky attendance-sticky-id" rowspan="2">Staff ID</th>
                    <th class="attendance-sticky attendance-sticky-name" rowspan="2">Staff Name</th>
                    <?php foreach ($staffRegisterWeeks as $week): ?>
                        <th class="attendance-week-heading" colspan="7"><?= e($week['start']->format('j M')) ?> – <?= e($week['end']->format('j M Y')) ?></th>
                    <?php endforeach; ?>
                    <th class="attendance-total-heading attendance-school-days-heading" rowspan="2">Term<br>School Days</th>
                    <th class="attendance-total-heading attendance-days-present-heading" rowspan="2">Term<br>Days Present</th>
                    <th class="attendance-total-heading" rowspan="2">Absent<br>Days</th>
                    <th class="attendance-total-heading" rowspan="2">Excused<br>Days</th>
                    <th class="attendance-total-heading" rowspan="2">Late<br>Arrivals</th>
                    <th class="attendance-total-heading" rowspan="2">Attendance<br>%</th>
                </tr>
                <tr>
                    <?php foreach ($staffRegisterWeeks as $week): ?>
                        <?php for ($weekdayIndex = 0; $weekdayIndex < 5; $weekdayIndex++):
                            $weekdayDate = $week['start']->modify('+' . $weekdayIndex . ' days');
                            $dateKey = $weekdayDate->format('Y-m-d');
                            $isSchoolDay = isset($staffWeeklyDays[$dateKey]) || isset($staffMonthDays[$dateKey]);
                            $isOutsideSelectedMonth = $weekdayDate->format('Y-m') !== $month;
                        ?>
                            <th class="attendance-day-heading <?= $isOutsideSelectedMonth ? 'attendance-outside-month' : '' ?> <?= !$isSchoolDay ? 'attendance-non-school-day' : '' ?>" title="<?= $isSchoolDay ? 'Configured weekday' : 'Holiday or non-school day' ?> · <?= e($dateKey) ?>">
                                <?= e($weekdayDate->format('j')) ?><small><?= strtoupper($weekdayDate->format('D')) ?><?= $isOutsideSelectedMonth ? ' · ' . strtoupper($weekdayDate->format('M')) : '' ?></small>
                            </th>
                        <?php endfor; ?>
                        <th class="attendance-week-total-heading">School<br>Days</th>
                        <th class="attendance-week-total-heading">Days<br>Present</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody><?php foreach ($staff as $index => $member):
                $staffId = (int) $member['id'];
                $monthCountForStaff = school_attendance_count_period(
                    array_keys($staffCumulativeDays),
                    $staffRecords[$staffId] ?? [],
                    true
                );
                $staffDenominator = $staffCumulativeSchoolDays;
                $monthExpectedForStaff = $staffDenominator;
                $monthRecordedForStaff = $monthCountForStaff['recorded_days'];
                $studentStaffPercentage = $monthCountForStaff['percentage'];
            ?>
                <tr><td class="attendance-sticky attendance-sticky-roll"><?= $index + 1 ?></td><td class="attendance-sticky attendance-sticky-id"><?= e((string) ($member['employee_number'] ?: '—')) ?></td><td class="attendance-sticky attendance-sticky-name"><?= e((string) $member['full_name']) ?></td>
                    <?php foreach ($staffRegisterWeeks as $weekStartKey => $week):
                        $weekSummary = $staffRegisterWeekSummaries[$staffId][$weekStartKey] ?? [
                            'scheduled_days' => 0,
                            'attended' => 0,
                            'recorded_days' => 0,
                            'incomplete' => false,
                        ];
                        $weekStartDate = $week['start']->format('Y-m-d');
                        $weekEndDate = $week['end']->format('Y-m-d');
                        for ($weekdayIndex = 0; $weekdayIndex < 5; $weekdayIndex++):
                            $staffDate = $week['start']->modify('+' . $weekdayIndex . ' days');
                            $dateKey = $staffDate->format('Y-m-d');
                            $scheduled = isset($staffWeeklyDays[$dateKey]) || isset($staffMonthDays[$dateKey]);
                            $entry = $staffRecords[$staffId][$dateKey] ?? null;
                            $entryStatus = $scheduled && $entry ? (string) $entry['status'] : '';
                            $staffStatusClass = !$staffBlankSheet && in_array($entryStatus, ['present', 'absent', 'excused', 'late'], true)
                                ? 'attendance-' . $entryStatus
                                : (!$staffBlankSheet && $scheduled ? 'attendance-unmarked' : '');
                            $staffDayLink = 'attendance.php?type=staff&month=' . rawurlencode($staffDate->format('Y-m'))
                                . ($selectedStaffTerm ? '&term_id=' . (int) $selectedStaffTerm['id'] : '')
                                . '&academic_year=' . rawurlencode($requestedAcademicYear)
                                . $staffGroupQuery
                                . '&week_start=' . rawurlencode($weekStartKey)
                                . '&date=' . rawurlencode($dateKey) . '&daywise=1';
                            $isHolidayCell = !$scheduled && $staffDate->format('Y-m') === $month;
                    ?>
                            <td class="attendance-day-cell <?= !$scheduled ? 'attendance-non-school-day' : '' ?> <?= e($staffStatusClass) ?>">
                                <?php if (!$staffBlankSheet && in_array($entryStatus, ['present', 'absent', 'excused', 'late'], true)): $indicator = school_attendance_status_indicator($entryStatus); ?>
                                    <a class="attendance-cell-link <?= e($indicator['class']) ?>" href="<?= e($staffDayLink) ?>" aria-label="<?= e($indicator['label']) ?> · <?= e($dateKey) ?><?= !empty($entry['arrival_at']) ? ' · arrived ' . e((new DateTimeImmutable((string) $entry['arrival_at']))->format('H:i')) : '' ?> · expected <?= e(substr((string) ($entry['expected_reporting_time'] ?? ''), 0, 5) ?: 'not recorded') ?>" title="<?= e($indicator['label']) ?> · <?= e($dateKey) ?><?= !empty($entry['arrival_at']) ? ' · arrived ' . e((new DateTimeImmutable((string) $entry['arrival_at']))->format('H:i')) : '' ?> · expected <?= e(substr((string) ($entry['expected_reporting_time'] ?? ''), 0, 5) ?: 'not recorded') ?>"><?= e($indicator['symbol']) ?></a>
                                <?php elseif (!$staffBlankSheet && $scheduled && $dateKey <= $todayDate && !$readOnlySupportPreview): ?>
                                    <a class="attendance-cell-link attendance-cell-empty attendance-unmarked" href="<?= e($staffDayLink) ?>" aria-label="Not recorded for <?= e($dateKey) ?> · Select to enter attendance" title="Not recorded for <?= e($dateKey) ?> · Select to enter attendance">—</a>
                                <?php elseif (!$staffBlankSheet && $scheduled): ?>
                                    <span class="attendance-cell-link attendance-cell-empty attendance-unmarked" aria-label="Not recorded for <?= e($dateKey) ?>" title="Not recorded">—</span>
                                <?php elseif ($isHolidayCell): ?>
                                    <span class="attendance-holiday-label">HOLIDAY</span>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                        <td class="attendance-week-calculation attendance-week-school-days" title="Scheduled school days in this week through <?= e(min($weekEndDate, $staffWeeklyReportingDate)) ?>"><?= $staffBlankSheet ? '' : (int) $weekSummary['scheduled_days'] ?></td>
                        <td class="attendance-week-calculation attendance-week-days-present<?= $weekSummary['incomplete'] ? ' attendance-provisional' : '' ?>" title="<?= $weekSummary['incomplete'] ? 'Provisional: ' . (int) $weekSummary['recorded_days'] . ' of ' . (int) $weekSummary['scheduled_days'] . ' scheduled days have records' : 'Present and Late days for this staff member in the selected week' ?>"><?= $staffBlankSheet ? '' : (int) $weekSummary['attended'] ?></td>
                    <?php endforeach; ?>
                    <td class="attendance-number attendance-school-days"><?= $staffBlankSheet ? '' : $staffDenominator ?></td>
                    <td class="attendance-number attendance-days-present<?= $monthRecordedForStaff < $monthExpectedForStaff ? ' attendance-provisional' : '' ?>" title="<?= $monthRecordedForStaff < $monthExpectedForStaff ? 'Incomplete attendance records' : 'Term-to-date staff attendance' ?>"><?= $staffBlankSheet ? '' : ($monthCountForStaff['present'] + $monthCountForStaff['late']) . ($monthRecordedForStaff < $monthExpectedForStaff ? ' · Incomplete' : '') ?></td>
                    <td class="attendance-number"><?= $monthCountForStaff['absent'] ?></td><td class="attendance-number"><?= $monthCountForStaff['excused'] ?></td><td class="attendance-number"><?= $monthCountForStaff['late'] ?></td><td class="attendance-number"><?= $monthRecordedForStaff < $monthExpectedForStaff ? 'Provisional' : ($studentStaffPercentage === null ? '—' : e(number_format($studentStaffPercentage, 2)) . '%') ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <p class="attendance-legend"><span class="attendance-present">✓ Present</span><span class="attendance-absent">✗ Absent</span><span class="attendance-excused">– Excused</span><span class="attendance-late">● Late</span><span>Blank: not recorded</span><span class="attendance-non-school-key">Holiday / closure</span></p>
    </section>
    <?php if ($staffDaywise): ?>
        <section class="panel attendance-register attendance-daywise-panel">
            <h2>Day-Wise Staff Attendance · <?= e($attendanceDate) ?></h2>
            <p class="muted">Arrival after <?= e($staffPolicy['reporting_time']) ?> plus the configured grace period of <?= (int) $staffPolicy['late_grace_minutes'] ?> minutes is saved as Late. Excused requires an explanation.</p>
            <?php if (!$isScheduledDate || $attendanceDate > $todayDate): ?><p class="notice notice-error">This date is not a past or current configured school day.</p>
            <?php elseif ($readOnlySupportPreview): ?><p class="notice">Support preview is read-only.</p>
            <?php else: ?>
                <form method="post" action="attendance.php?type=staff&amp;month=<?= e($month) ?><?= e($staffContextQuery) ?>&amp;academic_year=<?= e(rawurlencode($requestedAcademicYear)) ?>&amp;week_start=<?= e($selectedStaffRegisterWeek) ?>&amp;date=<?= rawurlencode($attendanceDate) ?>&amp;daywise=1" class="form-stack attendance-daily-form">
                    <?= csrf_field() ?><input type="hidden" name="type" value="staff"><input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">
                    <?php if ($selectedStaffTerm): ?><input type="hidden" name="term_id" value="<?= (int) $selectedStaffTerm['id'] ?>"><?php endif; ?>
                    <div class="table-scroll"><table><thead><tr><th>Staff member</th><th>Staff ID</th><th>Status</th><th>Expected time</th><th>Arrival time</th><th>Notes / Excuse reason</th></tr></thead><tbody>
                        <?php foreach ($staff as $member):
                            $staffId = (int) $member['id'];
                            $entry = $staffDailyRecords[$staffId] ?? null;
                            $currentStatus = (string) ($entry['status'] ?? '');
                            if ($currentStatus === 'pending') { $currentStatus = ''; }
                        ?>
                            <tr><td><?= e((string) $member['full_name']) ?></td><td><?= e((string) ($member['employee_number'] ?: '—')) ?></td><td>
                                <select name="staff[<?= $staffId ?>][status]" aria-label="Attendance status for <?= e((string) $member['full_name']) ?>">
                                    <option value="">Not recorded</option>
                                    <?php foreach (['present', 'absent', 'excused', 'late'] as $status): ?><option value="<?= e($status) ?>" <?= $currentStatus === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?>
                                </select>
                            </td><td><?= e(substr((string) ($entry['expected_reporting_time'] ?? $staffPolicy['reporting_time']), 0, 5)) ?></td><td><input type="time" name="staff[<?= $staffId ?>][arrival_time]" value="<?= $entry && $entry['arrival_at'] ? e((new DateTimeImmutable((string) $entry['arrival_at']))->format('H:i')) : '' ?>" aria-label="Arrival time for <?= e((string) $member['full_name']) ?>"></td><td><input type="text" name="staff[<?= $staffId ?>][remarks]" maxlength="255" value="<?= e((string) ($entry['remarks'] ?? '')) ?>" aria-label="Attendance notes for <?= e((string) $member['full_name']) ?>"></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <button class="button button-primary" type="submit">Save Staff Attendance</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php render_footer(); ?>
    <?php
    exit;
}

$classSql = 'SELECT sc.id, sc.level, sc.section, sc.academic_year
             FROM school_classes sc WHERE sc.school_id = ? AND sc.status = "active"';
if ($teacherAccount && !$readOnlyClassTeacherSupport) {
    $classSql .= ' AND EXISTS (
        SELECT 1 FROM teacher_class_assignments tca
        WHERE tca.school_id = sc.school_id AND tca.class_id = sc.id
          AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
    )';
}
$classSql .= ' ORDER BY sc.academic_year DESC, sc.level, sc.section';
$classStmt = db()->prepare($classSql);
$classStmt->execute($teacherAccount && !$readOnlyClassTeacherSupport ? [$schoolId, $teacherId] : [$schoolId]);
$classes = $classStmt->fetchAll();
$allClasses = $classes;
$availableAcademicYears = array_values(array_unique(array_map(
    static fn (array $class): string => (string) $class['academic_year'],
    $allClasses
)));
$requestedAcademicYear = trim((string) ($_GET['academic_year'] ?? ''));
if ($requestedAcademicYear !== '' && in_array($requestedAcademicYear, $availableAcademicYears, true)) {
    $classes = array_values(array_filter(
        $allClasses,
        static fn (array $class): bool => (string) $class['academic_year'] === $requestedAcademicYear
    ));
} else {
    $requestedAcademicYear = '';
}

$selectedClassId = filter_var($_GET['class_id'] ?? $_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
$selectedClass = null;
if ($selectedClassId !== false && $selectedClassId > 0) {
    foreach ($classes as $class) {
        if ((int) $class['id'] === $selectedClassId) {
            $selectedClass = $class;
            break;
        }
    }
}
if ($selectedClass === null && count($classes) === 1) {
    $selectedClass = $classes[0];
    $selectedClassId = (int) $selectedClass['id'];
}
$academicTerms = [];
$selectedTerm = null;
if ($selectedClass) {
    $termStmt = db()->prepare(
        'SELECT id, academic_year, term_name, starts_on, ends_on, is_current
         FROM school_terms
         WHERE school_id = ? AND academic_year = ?
         ORDER BY starts_on, id'
    );
    $termStmt->execute([$schoolId, (string) $selectedClass['academic_year']]);
    $academicTerms = $termStmt->fetchAll();
    $requestedTermId = filter_var($_GET['term_id'] ?? $_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
    foreach ($academicTerms as $term) {
        if ($requestedTermId !== false && (int) $term['id'] === (int) $requestedTermId) {
            $selectedTerm = $term;
            break;
        }
        if ($selectedTerm === null
            && (string) $term['starts_on'] <= $monthEnd->format('Y-m-d')
            && (string) $term['ends_on'] >= $monthStart->format('Y-m-d')) {
            $selectedTerm = $term;
        }
    }
    if ($selectedTerm === null) {
        foreach ($academicTerms as $term) {
            if ((int) $term['is_current'] === 1) {
                $selectedTerm = $term;
                break;
            }
        }
    }
    if ($selectedTerm === null && $academicTerms !== []) {
        $selectedTerm = $academicTerms[0];
    }
    if ($selectedTerm !== null) {
        $firstTermMonth = substr((string) $selectedTerm['starts_on'], 0, 7);
        $lastTermMonth = substr((string) $selectedTerm['ends_on'], 0, 7);
        if ($month < $firstTermMonth || $month > $lastTermMonth) {
            $month = $firstTermMonth;
            $parsedMonth = new DateTimeImmutable($month . '-01');
            $monthStart = $parsedMonth->modify('first day of this month');
            $monthEnd = $parsedMonth->modify('last day of this month');
            if (!$daywise) {
                $attendanceDate = max($month . '-01', (string) $selectedTerm['starts_on']);
            }
        }
        if (!$daywise && !isset($_GET['date']) && !isset($_POST['attendance_date'])
            && $attendanceDate < (string) $selectedTerm['starts_on']) {
            $attendanceDate = (string) $selectedTerm['starts_on'];
        }
    }
    $isScheduledDate = in_array(
        $attendanceDate,
        school_attendance_scheduled_dates(
            $schoolId,
            ['selected' => ['start' => $attendanceDate, 'end' => $attendanceDate]],
            $today
        )['selected'] ?? [],
        true
    ) && (int) (DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate)?->format('N') ?? 7) <= 5
        && (!$selectedTerm
        || ($attendanceDate >= (string) $selectedTerm['starts_on']
            && $attendanceDate <= (string) $selectedTerm['ends_on']));
}
$studentAttendanceReturnUrl = $selectedClass
    ? 'attendance.php?class_id=' . (int) $selectedClass['id']
        . '&month=' . rawurlencode($month)
        . ($selectedTerm ? '&term_id=' . (int) $selectedTerm['id'] : '')
        . (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['week_start'] ?? '')) === 1
            ? '&week_start=' . rawurlencode((string) $_GET['week_start'])
            : '')
        . '&date=' . rawurlencode($attendanceDate) . '&daywise=1'
    : 'attendance.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attendanceSyncRequest = (string) ($_POST['attendance_sync'] ?? '') === '1';
    $respondJson = static function (int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    $rejectAttendanceRequest = static function (string $message, int $status = 422) use (
        $attendanceSyncRequest,
        $respondJson,
        $studentAttendanceReturnUrl
    ): void {
        if ($attendanceSyncRequest) {
            $respondJson($status, ['error' => $message]);
        }
        flash('error', $message);
        redirect($studentAttendanceReturnUrl);
    };

    require_csrf();
    if (!$selectedClass) {
        $rejectAttendanceRequest('Choose a class you are authorized to manage attendance for.');
    }
    if ($attendanceDate > $todayDate || !$isScheduledDate) {
        $rejectAttendanceRequest('Student attendance can only be recorded for a past or current scheduled school day.');
    }
    $submitted = $_POST['student'] ?? [];
    if (!is_array($submitted)) {
        $rejectAttendanceRequest('No student attendance data was submitted.');
    }
    $expectedVersions = $_POST['expected_version'] ?? [];
    if (!is_array($expectedVersions)) {
        $rejectAttendanceRequest('Reload the register before saving attendance.');
    }
    $syncToken = $_POST['sync_token'] ?? '';
    if (!is_string($syncToken) || preg_match('/^[a-f0-9]{32}$/', $syncToken) !== 1) {
        $rejectAttendanceRequest('Reload the register before saving attendance.');
    }
    $studentStmt = db()->prepare(
        'SELECT ss.id FROM school_students ss
         JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         WHERE ss.school_id = ? AND ss.class_id = ? AND ss.school_status = "active"'
            . ($teacherAccount ? ' AND EXISTS (
                SELECT 1 FROM teacher_class_assignments tca
                WHERE tca.school_id = sc.school_id AND tca.class_id = sc.id
                  AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
            )' : '') . ' ORDER BY ss.id'
    );
    $studentStmt->execute($teacherAccount
        ? [$schoolId, (int) $selectedClass['id'], $teacherId]
        : [$schoolId, (int) $selectedClass['id']]);
    $authorizedStudentIds = array_map('intval', $studentStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($submitted === []) {
        $rejectAttendanceRequest('Select an attendance status for at least one student. Blank entries remain unrecorded.');
    }
    $authorizedStudentIdSet = array_fill_keys($authorizedStudentIds, true);
    $preparedRecords = [];
    foreach ($submitted as $studentKey => $status) {
        $studentId = filter_var($studentKey, FILTER_VALIDATE_INT);
        if ($studentId === false || !isset($authorizedStudentIdSet[$studentId])) {
            $rejectAttendanceRequest('A submitted student is not active in your assigned class.');
        }
        if (!is_string($status) || !in_array($status, ['present', 'absent', 'excused', 'late'], true)) {
            $rejectAttendanceRequest('Choose Present, Absent, Excused, or Late for each submitted student.');
        }
        $expectedVersion = filter_var($expectedVersions[$studentId] ?? null, FILTER_VALIDATE_INT);
        if ($expectedVersion === false || $expectedVersion < 0) {
            $rejectAttendanceRequest('Reload the register before saving attendance.');
        }
        $preparedRecords[] = [
            'student_id' => (int) $studentId,
            'status' => $status,
            'expected_version' => (int) $expectedVersion,
        ];
    }

    try {
        $saveResult = school_attendance_save_sync_batch(
            db(),
            $schoolId,
            (int) $selectedClass['id'],
            $attendanceDate,
            $teacherId,
            $syncToken,
            $preparedRecords
        );
    } catch (Throwable $exception) {
        if ($exception instanceof RuntimeException || $exception instanceof InvalidArgumentException) {
            if ($attendanceSyncRequest) {
                $respondJson(422, ['error' => $exception->getMessage()]);
            }
            flash('error', $exception->getMessage());
        } else {
            error_log('School attendance save failed: ' . $exception->getMessage());
            if ($attendanceSyncRequest) {
                $respondJson(500, ['error' => 'Attendance could not be saved. No entries from this submission were applied.']);
            }
            flash('error', 'Attendance could not be saved. No entries from this submission were applied.');
        }
        redirect($studentAttendanceReturnUrl);
    }
    if ($saveResult['conflicts'] !== []) {
        $message = 'Attendance changed since this register was loaded. No entries were saved; review the current register and re-enter your choices.';
        if ($attendanceSyncRequest) {
            $respondJson(409, ['error' => $message, 'conflicts' => $saveResult['conflicts']]);
        }
        $rejectAttendanceRequest($message, 409);
    }
    foreach ($saveResult['changed'] as $change) {
        audit($teacherId, 'school_student_attendance_changed', 'school_id=' . $schoolId . ';class_id=' . $selectedClass['id'] . ';date=' . $attendanceDate . ';student=' . $change);
    }
    $successMessage = 'Student attendance saved for ' . school_class_label($selectedClass) . ' on ' . $attendanceDate . '.';
    if ($attendanceSyncRequest) {
        $respondJson(200, [
            'message' => $successMessage,
            'records' => $saveResult['records'],
            'next_sync_token' => bin2hex(random_bytes(16)),
        ]);
    }
    flash('success', $successMessage);
    redirect($studentAttendanceReturnUrl);
}

$students = [];
$attendanceRecords = [];
$monthlyAttendance = [];
$monthlyLegacyStatus = [];
$monthlyCounts = ['present' => 0, 'absent' => 0, 'excused' => 0, 'late' => 0];
$selectedMonthCounts = ['present' => 0, 'absent' => 0, 'excused' => 0, 'late' => 0];
$monthlyScheduledDates = [];
$cumulativeScheduledDates = [];
$academicYearStart = $monthStart->format('Y-m-d');
if ($selectedClass) {
    $studentStmt = db()->prepare(
        'SELECT ss.id, ss.full_name, ss.admission_number, ss.photo_path, ss.gender,
                guardian.full_name AS guardian_name, guardian.email AS guardian_email,
                guardian.phone AS guardian_phone
         FROM school_students ss
         LEFT JOIN school_guardians guardian ON guardian.id = ss.guardian_id AND guardian.school_id = ss.school_id
         WHERE ss.school_id = ? AND ss.class_id = ? AND ss.school_status = "active" ORDER BY ss.id'
    );
    $studentStmt->execute([$schoolId, (int) $selectedClass['id']]);
    $students = $studentStmt->fetchAll();
    $attendanceStmt = db()->prepare(
        'SELECT student_id, status, legacy_status, row_version FROM student_attendance
         WHERE school_id = ? AND class_id = ? AND attendance_date = ?'
    );
    $attendanceStmt->execute([$schoolId, (int) $selectedClass['id'], $attendanceDate]);
    foreach ($attendanceStmt->fetchAll() as $record) {
        $attendanceRecords[(int) $record['student_id']] = [
            'status' => (string) $record['status'],
            'legacy_status' => $record['legacy_status'] === null ? null : (string) $record['legacy_status'],
            'row_version' => (int) $record['row_version'],
        ];
    }
    $yearStartStmt = db()->prepare(
        'SELECT MIN(starts_on) FROM school_terms
         WHERE school_id = ? AND academic_year = ?'
    );
    $yearStartStmt->execute([$schoolId, (string) $selectedClass['academic_year']]);
    $configuredYearStart = $yearStartStmt->fetchColumn();
    if (is_string($configuredYearStart) && $configuredYearStart !== '') {
        $academicYearStart = $configuredYearStart;
    }
    $termStart = $selectedTerm !== null
        ? (string) $selectedTerm['starts_on']
        : $academicYearStart;
    $termEnd = $selectedTerm !== null
        ? (string) $selectedTerm['ends_on']
        : $monthEnd->format('Y-m-d');
    $attendanceSummaryEnd = $daywise ? $attendanceDate : $monthEnd->format('Y-m-d');
    $cumulativeEnd = min($attendanceSummaryEnd, $todayDate, $termEnd);
    $monthSchedule = school_attendance_scheduled_dates($schoolId, [
        'month' => ['start' => $monthStart->format('Y-m-d'), 'end' => $monthEnd->format('Y-m-d')],
    ], $monthEnd);
    $cumulativeSchedule = school_attendance_scheduled_dates($schoolId, [
        'cumulative' => ['start' => $termStart, 'end' => $cumulativeEnd],
    ], $today);
    $monthlyScheduledDates = array_fill_keys($monthSchedule['month'] ?? [], true);
    $cumulativeScheduledDates = array_fill_keys($cumulativeSchedule['cumulative'] ?? [], true);
    foreach ([&$monthlyScheduledDates, &$cumulativeScheduledDates] as &$scheduledDays) {
        foreach (array_keys($scheduledDays) as $scheduledDate) {
            if ($scheduledDate < $termStart || $scheduledDate > $termEnd
                || (int) (new DateTimeImmutable($scheduledDate))->format('N') >= 6) {
                unset($scheduledDays[$scheduledDate]);
            }
        }
    }
    unset($scheduledDays);

    $firstRegisterWeek = $monthStart->modify('monday this week');
    $lastRegisterWeekEnd = $monthEnd->modify('sunday this week');
    $weeklyTermEnd = $selectedTerm !== null
        ? (string) $selectedTerm['ends_on']
        : $lastRegisterWeekEnd->format('Y-m-d');
    $weeklyCalendarStart = max($termStart, $firstRegisterWeek->format('Y-m-d'));
    $weeklyCalendarEnd = min($weeklyTermEnd, $lastRegisterWeekEnd->format('Y-m-d'));
    $weeklyCalendar = school_attendance_scheduled_dates($schoolId, [
        'register_weeks' => ['start' => $weeklyCalendarStart, 'end' => $weeklyCalendarEnd],
    ], $today);
    $weeklyScheduledDates = array_fill_keys($weeklyCalendar['register_weeks'] ?? [], true);
    foreach (array_keys($weeklyScheduledDates) as $scheduledDate) {
        if ($scheduledDate < $termStart || $scheduledDate > $termEnd
            || (int) (new DateTimeImmutable($scheduledDate))->format('N') >= 6) {
            unset($weeklyScheduledDates[$scheduledDate]);
        }
    }
    $weeklyAttendanceEnd = min($todayDate, $weeklyCalendarEnd);
    $attendanceRangeEnd = max($monthEnd->format('Y-m-d'), $weeklyAttendanceEnd);
    $attendanceRangeStart = min($academicYearStart, $weeklyCalendarStart);

    $attendanceRangeStmt = db()->prepare(
        'SELECT attendance.student_id, attendance.attendance_date, attendance.status, attendance.legacy_status
         FROM student_attendance attendance
         WHERE attendance.school_id = ? AND attendance.class_id = ?
           AND attendance.attendance_date BETWEEN ? AND ?
         ORDER BY attendance.attendance_date, attendance.student_id'
    );
    $attendanceRangeStmt->execute([
        $schoolId,
        (int) $selectedClass['id'],
        $attendanceRangeStart,
        $attendanceRangeEnd,
    ]);
    foreach ($attendanceRangeStmt->fetchAll() as $record) {
        $studentId = (int) $record['student_id'];
        $date = (string) $record['attendance_date'];
        $status = (string) $record['status'];
        $legacyStatus = $record['legacy_status'] === null ? null : (string) $record['legacy_status'];
        $monthlyAttendance[$studentId][$date] = $status;
        if ($legacyStatus !== null) {
            $monthlyLegacyStatus[$studentId][$date] = $legacyStatus;
        }
        if (isset($cumulativeScheduledDates[$date])
            && $date <= $cumulativeEnd) {
            $recordStatus = $legacyStatus === 'excused'
                ? 'excused'
                : ($legacyStatus === 'late' ? 'late' : $status);
            $monthlyCounts[$recordStatus] = ($monthlyCounts[$recordStatus] ?? 0) + 1;
        }
        if (isset($monthlyScheduledDates[$date]) && $date <= $cumulativeEnd) {
            $recordStatus = $legacyStatus === 'excused'
                ? 'excused'
                : ($legacyStatus === 'late' ? 'late' : $status);
            $selectedMonthCounts[$recordStatus] = ($selectedMonthCounts[$recordStatus] ?? 0) + 1;
        }
    }
}

$monthPresent = $monthlyCounts['present'];
$studentMonthStatusChart = ['present' => 0, 'absent' => 0, 'excused' => 0, 'late' => 0];
foreach ($students as $student) {
    $studentId = (int) $student['id'];
    foreach (array_keys($monthlyScheduledDates) as $scheduledDate) {
        if ($scheduledDate > $cumulativeEnd || !isset($monthlyAttendance[$studentId][$scheduledDate])) {
            continue;
        }
        $legacyStatus = $monthlyLegacyStatus[$studentId][$scheduledDate] ?? null;
        $status = $legacyStatus === 'excused'
            ? 'excused'
            : ($legacyStatus === 'late' ? 'late' : (string) $monthlyAttendance[$studentId][$scheduledDate]);
        if (array_key_exists($status, $studentMonthStatusChart)) {
            $studentMonthStatusChart[$status]++;
        }
    }
}
$monthExpectedRecords = count($students) * count($cumulativeScheduledDates);
$monthRecordedRecords = 0;
foreach ($students as $student) {
    foreach (array_keys($cumulativeScheduledDates) as $scheduledDate) {
        if (isset($monthlyAttendance[(int) $student['id']][$scheduledDate])) {
            $monthRecordedRecords++;
        }
    }
}
$monthIncomplete = $monthRecordedRecords < $monthExpectedRecords;
$monthPresentPercent = !$monthIncomplete && $monthExpectedRecords > 0
    ? ($monthPresent / $monthExpectedRecords) * 100
    : null;
$scheduledMonthDays = count($monthlyScheduledDates);
$monthlySchoolDaysToDate = count(array_filter(
    array_keys($monthlyScheduledDates),
    static fn (string $date): bool => $date <= $cumulativeEnd
));
$monthlyExpectedRecords = count($students) * $monthlySchoolDaysToDate;
$monthlyRecordedRecords = 0;
foreach ($students as $student) {
    foreach (array_keys($monthlyScheduledDates) as $scheduledDate) {
        if ($scheduledDate <= $cumulativeEnd
            && isset($monthlyAttendance[(int) $student['id']][$scheduledDate])) {
            $monthlyRecordedRecords++;
        }
    }
}
$monthlyIncomplete = $monthlyRecordedRecords < $monthlyExpectedRecords;
$studentMonthNotStarted = $monthStart->format('Y-m') > $today->format('Y-m');
$studentMonthSummaryCaption = $daywise ? 'up to selected date' : 'month to date';
$studentMonthMin = $selectedTerm === null ? '' : substr((string) $selectedTerm['starts_on'], 0, 7);
$studentMonthMax = $selectedTerm === null ? '' : substr((string) $selectedTerm['ends_on'], 0, 7);
$studentDateMin = $selectedTerm === null || (string) $selectedTerm['starts_on'] > $todayDate
    ? ''
    : (string) $selectedTerm['starts_on'];
$studentDateMax = $selectedTerm === null
    ? $todayDate
    : min($todayDate, (string) $selectedTerm['ends_on']);
$previousMonth = $monthStart->modify('-1 month');
$nextMonth = $monthStart->modify('+1 month');
$canNavigatePrevious = $selectedTerm === null
    || $previousMonth->modify('last day of this month')->format('Y-m-d') >= (string) $selectedTerm['starts_on'];
$canNavigateNext = $selectedTerm === null
    || $nextMonth->format('Y-m-d') <= (string) $selectedTerm['ends_on'];
$studentMonthUrl = static fn (DateTimeImmutable $targetMonth): string => 'attendance.php?class_id='
    . (int) ($selectedClass['id'] ?? 0)
    . '&month=' . rawurlencode($targetMonth->format('Y-m'))
    . ($selectedTerm ? '&term_id=' . (int) $selectedTerm['id'] : '')
    . '&academic_year=' . rawurlencode((string) ($selectedClass['academic_year'] ?? $requestedAcademicYear));
$firstRegisterWeek ??= $monthStart->modify('monday this week');
$lastRegisterWeekEnd ??= $monthEnd->modify('sunday this week');
$weeklyTermEnd ??= $selectedTerm !== null
    ? (string) $selectedTerm['ends_on']
    : $lastRegisterWeekEnd->format('Y-m-d');
$weeklyScheduledDates ??= [];
$weeklyReportingDate = $daywise
    ? min($attendanceDate, $todayDate, $weeklyTermEnd)
    : min($todayDate, $weeklyTermEnd);
$studentRegisterWeeks = [];
for ($weekStart = $firstRegisterWeek; $weekStart <= $monthEnd; $weekStart = $weekStart->modify('+7 days')) {
    $weekEnd = $weekStart->modify('+6 days');
    $weekStartKey = $weekStart->format('Y-m-d');
    $studentRegisterWeeks[$weekStartKey] = [
        'start' => $weekStart,
        'end' => $weekEnd,
    ];
}
$weekReferenceDate = $daywise
    ? $attendanceDate
    : ($monthStart <= $today && $monthEnd >= $today
        ? $todayDate
        : ($monthStart > $today ? $monthStart->format('Y-m-d') : $monthEnd->format('Y-m-d')));
$selectedRegisterWeek = (new DateTimeImmutable($weekReferenceDate))->modify('monday this week')->format('Y-m-d');
$requestedRegisterWeek = trim((string) ($_GET['week_start'] ?? ''));
if (isset($studentRegisterWeeks[$requestedRegisterWeek])) {
    $selectedRegisterWeek = $requestedRegisterWeek;
}
$studentRegisterWeekSummaries = [];
$registerWeekScheduledDates = [];
foreach ($studentRegisterWeeks as $weekStartKey => $week) {
    $weekStartDate = $week['start']->format('Y-m-d');
    $weekEndDate = $week['end']->format('Y-m-d');
    $weekCutoff = min($weekEndDate, $weeklyReportingDate);
    $registerWeekScheduledDates[$weekStartKey] = array_values(array_filter(
        array_keys($weeklyScheduledDates),
        static fn (string $date): bool => $date >= $weekStartDate
            && $date <= $weekEndDate
            && $date <= $weekCutoff
    ));
}
$selectedWeekSchoolDays = count($registerWeekScheduledDates[$selectedRegisterWeek] ?? []);
$selectedWeekClassPresent = 0;
foreach ($students as $student) {
    $studentId = (int) $student['id'];
    $studentRecords = [];
    foreach ($monthlyAttendance[$studentId] ?? [] as $recordDate => $status) {
        $studentRecords[$recordDate] = [
            'status' => $status,
            'legacy_status' => $monthlyLegacyStatus[$studentId][$recordDate] ?? null,
        ];
    }
    foreach ($studentRegisterWeeks as $weekStartKey => $week) {
        $weekScheduledDates = $registerWeekScheduledDates[$weekStartKey] ?? [];
        $studentRegisterWeekSummaries[$studentId][$weekStartKey] = school_attendance_count_period(
            $weekScheduledDates,
            $studentRecords
        );
        if ($weekStartKey === $selectedRegisterWeek) {
            $selectedWeekClassPresent += $studentRegisterWeekSummaries[$studentId][$weekStartKey]['present'];
        }
    }
}
$attendanceSummaryRange = $daywise ? 'selected date' : 'selected month';
$monthQuery = 'month=' . rawurlencode($month);
$canEditDaily = !$readOnlyClassTeacherSupport && $isScheduledDate && $attendanceDate <= $todayDate;
render_header('Monthly attendance register');
?>
<p class="action-task-toolbar"><a class="button button-quiet" href="attendance.php">Back to Actions</a></p>
<section class="panel attendance-page-heading">
    <p class="eyebrow">ATTENDANCE</p>
    <h1>Monthly attendance register</h1>
    <p class="muted">Term totals use scheduled school days through the <?= e($attendanceSummaryRange) ?>. Only explicitly recorded Present statuses count as attendance; missing records are provisional, not absences.</p>
</section>
<section class="attendance-summary-cards" aria-label="Attendance summary for <?= e($month) ?>">
    <article class="attendance-summary-card attendance-card-total"><strong><?= $selectedClass ? $scheduledMonthDays : '—' ?></strong><span>Monthly School Days · full month</span></article>
    <article class="attendance-summary-card attendance-card-count-present"><strong><?= $selectedClass ? (int) $selectedMonthCounts['present'] : '—' ?></strong><span>Monthly Days Present · <?= e($studentMonthSummaryCaption) ?><?= $studentMonthNotStarted ? ' · Not started' : ($monthlyIncomplete ? ' · Provisional' : '') ?></span></article>
    <article class="attendance-summary-card attendance-card-total"><strong><?= $selectedClass ? count($cumulativeScheduledDates) : '—' ?></strong><span>Cumulative School Days · selected term</span></article>
    <article class="attendance-summary-card attendance-card-present"><strong><?= $selectedClass ? (int) $monthPresent : '—' ?></strong><span>Cumulative Days Present · class total<?= $monthIncomplete ? ' · Provisional' : ($monthPresentPercent === null ? '' : ' · ' . number_format($monthPresentPercent, 2) . '%') ?></span></article>
    <?php if ($selectedClass): ?>
        <article class="attendance-summary-card attendance-card-total"><strong><?= $selectedWeekSchoolDays ?></strong><span>Number of School Days · selected week</span></article>
        <article class="attendance-summary-card attendance-card-count-present"><strong><?= $selectedWeekClassPresent ?></strong><span>Class Days Present · student-days; individual totals are shown in each student's weekly row</span></article>
    <?php endif; ?>
</section>
<?php if ($selectedClass): ?>
<section class="panel spaced-top" aria-label="Student attendance by status">
    <h2>Recorded attendance by status</h2>
    <p class="muted">Recorded student-days in <?= e(school_class_label($selectedClass)) ?> for <?= e($month) ?> through <?= e($studentMonthSummaryCaption) ?>. Blank entries are not included; missing scheduled attendance is not counted as absence.</p>
    <?php if (array_sum($studentMonthStatusChart) > 0): ?>
        <?php render_dashboard_bar_chart('Recorded student-days by status', [
            'Present' => $studentMonthStatusChart['present'],
            'Absent' => $studentMonthStatusChart['absent'],
            'Excused' => $studentMonthStatusChart['excused'],
            'Late' => $studentMonthStatusChart['late'],
        ], 'student-days'); ?>
    <?php else: ?>
        <p class="muted">No student attendance has been recorded for this reporting period.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="panel attendance-month-actions" aria-label="Attendance register actions">
    <details class="attendance-strength">
        <summary class="button">Student Strength</summary>
        <p><?= $selectedClass ? '<strong>' . count($students) . '</strong> active student' . (count($students) === 1 ? '' : 's') . ' in the selected class.' : 'Select a class to see its active student strength.' ?></p>
    </details>
    <?php if ($selectedClass): ?>
        <form method="get" action="attendance.php" class="attendance-day-picker">
            <input type="hidden" name="class_id" value="<?= (int) $selectedClass['id'] ?>">
            <input type="hidden" name="month" value="<?= e($month) ?>">
            <input type="hidden" name="academic_year" value="<?= e((string) $selectedClass['academic_year']) ?>">
            <?php if ($selectedTerm): ?><input type="hidden" name="term_id" value="<?= (int) $selectedTerm['id'] ?>"><?php endif; ?>
            <label for="daywise-date">Day-Wise Attendance date</label>
            <input id="daywise-date" name="date" type="date" value="<?= e($attendanceDate) ?>" <?= $studentDateMin !== '' ? 'min="' . e($studentDateMin) . '"' : '' ?> max="<?= e($studentDateMax) ?>" required>
            <input type="hidden" name="daywise" value="1">
            <button class="button" type="submit">Day-Wise Attendance</button>
        </form>
        <a class="button" href="attendance.php?class_id=<?= (int) $selectedClass['id'] ?>&amp;<?= e($monthQuery) ?><?= $selectedTerm ? '&amp;term_id=' . (int) $selectedTerm['id'] : '' ?>&amp;blank=1">Blank Attendance Sheet</a>
    <?php endif; ?>
    <button class="button button-primary" type="button" data-print-button>Print</button>
</section>
<section class="panel attendance-filter-panel">
    <form method="get" action="attendance.php" class="attendance-month-filters">
        <label for="attendance-academic-year">Academic year
            <select id="attendance-academic-year" name="academic_year" onchange="this.form.submit()">
                <option value="">All academic years</option>
                <?php foreach ($availableAcademicYears as $academicYear): ?><option value="<?= e($academicYear) ?>" <?= ($requestedAcademicYear !== '' ? $requestedAcademicYear : (string) ($selectedClass['academic_year'] ?? '')) === $academicYear ? 'selected' : '' ?>><?= e(school_academic_year_label($academicYear)) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label for="class_id">Class
            <select id="class_id" name="class_id" required>
                <option value="">Select class</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int) $class['id'] ?>" <?= $selectedClass && (int) $selectedClass['id'] === (int) $class['id'] ? 'selected' : '' ?>><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="attendance-month">Month
            <input id="attendance-month" type="month" name="month" value="<?= e($month) ?>" <?= $studentMonthMin !== '' ? 'min="' . e($studentMonthMin) . '"' : '' ?> <?= $studentMonthMax !== '' ? 'max="' . e($studentMonthMax) . '"' : '' ?> required>
        </label>
        <?php if ($selectedClass): ?>
            <label for="attendance-week-start">Weekly summary period
                <select id="attendance-week-start" name="week_start">
                    <?php foreach ($studentRegisterWeeks as $weekStartKey => $week): ?>
                        <option value="<?= e($weekStartKey) ?>" <?= $selectedRegisterWeek === $weekStartKey ? 'selected' : '' ?>><?= e($week['start']->format('j M')) ?> – <?= e($week['end']->format('j M Y')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="attendance-term">Academic term
                <select id="attendance-term" name="term_id">
                    <?php if ($academicTerms === []): ?><option value="">No configured terms</option><?php endif; ?>
                    <?php foreach ($academicTerms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>" <?= $selectedTerm && (int) $selectedTerm['id'] === (int) $term['id'] ? 'selected' : '' ?>><?= e((string) $term['term_name'] . ' · ' . (string) $term['starts_on'] . ' to ' . (string) $term['ends_on']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <button class="button button-primary" type="submit">Load Register</button>
    </form>
</section>
<?php if (!$selectedClass): ?>
    <section class="panel"><p class="muted"><?= $classes === [] ? 'No assigned active classes are available to you.' : 'Select an assigned class to view its attendance register.' ?></p></section>
<?php else: ?>
    <section class="panel attendance-month-panel" id="attendance-monthly-register">
        <div class="attendance-register-heading">
            <div>
                <h2><?= e(school_class_label($selectedClass)) ?> · <?= e(school_academic_year_label((string) $selectedClass['academic_year'])) ?></h2>
                <p class="muted"><?= e($monthStart->format('F Y')) ?><?= $selectedTerm ? ' · ' . e((string) $selectedTerm['term_name']) : '' ?> · <?= count($students) ?> active student<?= count($students) === 1 ? '' : 's' ?> · <?= $scheduledMonthDays ?> applicable school days</p>
            </div>
            <?php if ($blankSheet): ?><strong class="attendance-blank-label">Blank register for printing</strong><?php endif; ?>
        </div>
        <nav class="attendance-month-navigation" aria-label="Student attendance month navigation">
            <?php if ($canNavigatePrevious): ?><a class="button button-quiet" href="<?= e($studentMonthUrl($previousMonth)) ?>">Previous Month</a><?php else: ?><span class="button button-quiet" aria-disabled="true">Previous Month</span><?php endif; ?>
            <strong><?= e($monthStart->format('F Y')) ?></strong>
            <?php if ($canNavigateNext): ?><a class="button button-quiet" href="<?= e($studentMonthUrl($nextMonth)) ?>">Next Month</a><?php else: ?><span class="button button-quiet" aria-disabled="true">Next Month</span><?php endif; ?>
        </nav>
        <?php if ($readOnlyClassTeacherSupport): ?><p class="notice">Support preview is read-only. Attendance updates are disabled.</p><?php endif; ?>
        <?php if ($students === []): ?><p class="muted">No active students are enrolled in this class.</p><?php else: ?>
        <div class="attendance-month-scroll">
            <table class="attendance-month-table">
                <thead>
                    <tr>
                        <th class="attendance-sticky attendance-sticky-roll" rowspan="2">Roll No.</th>
                        <th class="attendance-sticky attendance-sticky-id" rowspan="2">ID No.</th>
                        <th class="attendance-sticky attendance-sticky-name" rowspan="2">Name</th>
                        <?php foreach ($studentRegisterWeeks as $week): ?>
                            <th class="attendance-week-heading" colspan="7"><?= e($week['start']->format('j M')) ?> – <?= e($week['end']->format('j M Y')) ?></th>
                        <?php endforeach; ?>
                        <th class="attendance-total-heading attendance-school-days-heading" rowspan="2">Term<br>School Days</th>
                        <th class="attendance-total-heading attendance-days-present-heading" rowspan="2">Term<br>Days Present</th>
                    </tr>
                    <tr>
                        <?php foreach ($studentRegisterWeeks as $weekStartKey => $week): ?>
                            <?php for ($weekdayIndex = 0; $weekdayIndex < 5; $weekdayIndex++):
                                $weekdayDate = $week['start']->modify('+' . $weekdayIndex . ' days');
                                $dateKey = $weekdayDate->format('Y-m-d');
                                $isSchoolDay = isset($weeklyScheduledDates[$dateKey]) || isset($monthlyScheduledDates[$dateKey]);
                                $isOutsideSelectedMonth = $weekdayDate->format('Y-m') !== $month;
                            ?>
                                <th class="attendance-day-heading <?= $isOutsideSelectedMonth ? 'attendance-outside-month' : '' ?> <?= !$isSchoolDay ? 'attendance-non-school-day' : '' ?>" title="<?= $isSchoolDay ? 'Configured weekday' : 'Holiday or non-school day' ?> · <?= e($dateKey) ?>">
                                    <?= e($weekdayDate->format('j')) ?><small><?= strtoupper($weekdayDate->format('D')) ?><?= $isOutsideSelectedMonth ? ' · ' . strtoupper($weekdayDate->format('M')) : '' ?></small>
                                </th>
                            <?php endfor; ?>
                            <th class="attendance-week-total-heading">School<br>Days</th>
                            <th class="attendance-week-total-heading">Days<br>Present</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $index => $student):
                    $studentId = (int) $student['id'];
                    $studentRecords = [];
                    foreach ($monthlyAttendance[$studentId] ?? [] as $recordDate => $status) {
                        $studentRecords[$recordDate] = [
                            'status' => $status,
                            'legacy_status' => $monthlyLegacyStatus[$studentId][$recordDate] ?? null,
                        ];
                    }
                    $studentSummary = school_attendance_count_period(
                        array_keys($cumulativeScheduledDates),
                        $studentRecords
                    );
                    $cumulativeAttended = $studentSummary['present'];
                    $studentRecordedDays = $studentSummary['recorded_days'];
                    $studentIncomplete = $studentSummary['incomplete'];
                ?>
                    <tr>
                        <td class="attendance-sticky attendance-sticky-roll"><?= $index + 1 ?></td>
                        <td class="attendance-sticky attendance-sticky-id"><?= e((string) ($student['admission_number'] ?: '—')) ?></td>
                        <td class="attendance-sticky attendance-sticky-name"><?= e((string) $student['full_name']) ?></td>
                        <?php foreach ($studentRegisterWeeks as $weekStartKey => $week):
                            $weekStartDate = $week['start']->format('Y-m-d');
                            $weekEndDate = $week['end']->format('Y-m-d');
                            $weekSummary = $studentRegisterWeekSummaries[$studentId][$weekStartKey] ?? [
                                'scheduled_days' => 0,
                                'present' => 0,
                                'recorded_days' => 0,
                                'incomplete' => false,
                            ];
                            for ($weekdayIndex = 0; $weekdayIndex < 5; $weekdayIndex++):
                                $weekday = $week['start']->modify('+' . $weekdayIndex . ' days');
                                $dateKey = $weekday->format('Y-m-d');
                                $isSchoolDay = isset($weeklyScheduledDates[$dateKey]) || isset($monthlyScheduledDates[$dateKey]);
                                $legacyStatus = $monthlyLegacyStatus[$studentId][$dateKey] ?? null;
                                $status = $isSchoolDay
                                    ? ($legacyStatus === 'excused'
                                        ? 'excused'
                                        : ($legacyStatus === 'late' ? 'late' : ($monthlyAttendance[$studentId][$dateKey] ?? '')))
                                    : '';
                                $studentStatusClass = !$blankSheet && in_array($status, ['present', 'absent', 'excused', 'late'], true)
                                    ? 'attendance-' . $status
                                    : (!$blankSheet && $isSchoolDay ? 'attendance-unmarked' : '');
                                $dayLink = 'attendance.php?class_id=' . (int) $selectedClass['id']
                                    . '&month=' . rawurlencode($weekday->format('Y-m'))
                                    . ($selectedTerm ? '&term_id=' . (int) $selectedTerm['id'] : '')
                                    . '&week_start=' . rawurlencode($weekStartKey)
                                    . '&date=' . rawurlencode($dateKey) . '&daywise=1';
                                $isHolidayCell = !$isSchoolDay && $weekday->format('Y-m') === $month;
                        ?>
                                <td class="attendance-day-cell <?= !$isSchoolDay ? 'attendance-non-school-day' : '' ?> <?= e($studentStatusClass) ?>">
                                    <?php if (!$blankSheet && $status !== ''):
                                        $indicator = school_attendance_status_indicator($status);
                                    ?>
                                        <a class="attendance-cell-link <?= e($indicator['class']) ?>" href="<?= e($dayLink) ?>" aria-label="<?= e((string) $student['full_name']) ?>: <?= e($indicator['label']) ?> on <?= e($dateKey) ?>" title="<?= e((string) $student['full_name']) ?>: <?= e($indicator['label']) ?> on <?= e($dateKey) ?>"><?= e($indicator['symbol']) ?></a>
                                    <?php elseif (!$blankSheet && !$readOnlyClassTeacherSupport && $isSchoolDay && $dateKey <= $todayDate): ?>
                                        <a class="attendance-cell-link attendance-cell-empty attendance-unmarked" href="<?= e($dayLink) ?>" aria-label="Not recorded for <?= e((string) $student['full_name']) ?> on <?= e($dateKey) ?> · Open attendance to enter" title="Not recorded for <?= e((string) $student['full_name']) ?> on <?= e($dateKey) ?> · Open attendance to enter">—</a>
                                    <?php elseif (!$blankSheet && $isSchoolDay): ?>
                                        <span class="attendance-cell-link attendance-cell-empty attendance-unmarked" aria-label="Not recorded for <?= e((string) $student['full_name']) ?> on <?= e($dateKey) ?>" title="Not recorded for <?= e((string) $student['full_name']) ?> on <?= e($dateKey) ?>">—</span>
                                    <?php elseif ($isHolidayCell): ?>
                                        <span class="attendance-holiday-label">HOLIDAY</span>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                            <td class="attendance-week-calculation attendance-week-school-days" title="Scheduled school days in this week through <?= e(min($weekEndDate, $weeklyReportingDate)) ?>"><?= $blankSheet ? '' : (int) $weekSummary['scheduled_days'] ?></td>
                            <td class="attendance-week-calculation attendance-week-days-present<?= $weekSummary['incomplete'] ? ' attendance-provisional' : '' ?>" title="<?= $weekSummary['incomplete'] ? 'Provisional: ' . (int) $weekSummary['recorded_days'] . ' of ' . (int) $weekSummary['scheduled_days'] . ' scheduled days have records' : 'Recorded Present days for this student in the selected week' ?>"><?= $blankSheet ? '' : (int) $weekSummary['present'] ?></td>
                        <?php endforeach; ?>
                        <td class="attendance-number attendance-school-days"><?= $blankSheet ? '' : count($cumulativeScheduledDates) ?></td>
                        <td class="attendance-number attendance-days-present<?= $studentIncomplete ? ' attendance-provisional' : '' ?>" title="<?= $studentIncomplete ? 'Incomplete: ' . $studentRecordedDays . ' of ' . count($cumulativeScheduledDates) . ' scheduled days recorded' : 'Complete term-to-date attendance' ?>"><?= $blankSheet ? '' : $cumulativeAttended . ($studentIncomplete ? ' · Incomplete' : '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p class="attendance-legend"><span class="attendance-present">✓ Present</span><span class="attendance-absent">✗ Absent</span><span class="attendance-late">● Late (historical)</span><span>Blank: not recorded</span><span class="attendance-non-school-key">Holiday / closure</span></p>
        <p class="muted attendance-calculation-note">School Days and Days Present are cumulative from the selected term start through the <?= e($attendanceSummaryRange) ?>. Days Present counts only explicit Present records; Late and Excused remain distinct historical statuses. Missing records make totals provisional and are never counted as absences.</p>
    </section>
    <?php if ($daywise): ?>
        <?php
        $dailyTitle = DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
        ?>
        <section class="panel attendance-register attendance-daywise-panel">
            <h2>Day-Wise Attendance · <?= e($dailyTitle ? $dailyTitle->format('j F Y') : $attendanceDate) ?></h2>
            <p class="muted"><?= e(school_class_label($selectedClass)) ?> · choose a status for students with recorded attendance; leave others blank to keep them unrecorded.</p>
            <?php if (!$isScheduledDate || $attendanceDate > $todayDate): ?>
                <p class="notice notice-error">This date is not an applicable school day. Attendance cannot be recorded on weekends, holidays, closures, dates outside configured terms, or future dates unless configured as a school day.</p>
            <?php elseif ($students === []): ?>
                <p class="muted">No active students are enrolled in this class.</p>
            <?php elseif (!$canEditDaily): ?>
                <p class="notice">Support preview is read-only. Sign in as an authorized Class Teacher to record or correct attendance.</p>
            <?php else: ?>
                <form method="post" action="attendance.php?class_id=<?= (int) $selectedClass['id'] ?>&amp;month=<?= rawurlencode($month) ?>&amp;date=<?= rawurlencode($attendanceDate) ?>&amp;daywise=1<?= $selectedTerm ? '&amp;term_id=' . (int) $selectedTerm['id'] : '' ?>" class="form-stack attendance-daily-form" data-attendance-sync-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="class_id" value="<?= (int) $selectedClass['id'] ?>">
                    <input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">
                    <input type="hidden" name="sync_token" value="<?= e(bin2hex(random_bytes(16))) ?>">
                    <input type="hidden" name="attendance_sync" value="0">
                    <?php if ($selectedTerm): ?><input type="hidden" name="term_id" value="<?= (int) $selectedTerm['id'] ?>"><?php endif; ?>
                    <div class="table-scroll"><table><thead><tr><th>Student</th><th>ID No.</th><th>Earlier status</th><th>Present</th><th>Absent</th><th>Excused</th><th>Late</th></tr></thead><tbody>
                        <?php foreach ($students as $student):
                            $studentId = (int) $student['id'];
                            $savedAttendance = $attendanceRecords[$studentId] ?? null;
                            $currentStatus = $savedAttendance['status'] ?? '';
                            $legacyLabel = match ($savedAttendance['legacy_status'] ?? null) {
                                'excused' => 'Historical Excused',
                                'late' => 'Historical Late',
                                default => '—',
                            };
                        ?>
                            <tr><td><?= e((string) $student['full_name']) ?><input type="hidden" name="expected_version[<?= $studentId ?>]" value="<?= $savedAttendance ? (int) $savedAttendance['row_version'] : 0 ?>"></td><td><?= e((string) ($student['admission_number'] ?: '—')) ?></td><td><?= e($legacyLabel) ?></td>
                                <?php foreach (['present', 'absent', 'excused', 'late'] as $status): $indicator = school_attendance_status_indicator($status); ?>
                                    <td><label class="attendance-choice <?= e($indicator['class']) ?>"><input type="radio" name="student[<?= $studentId ?>]" value="<?= e($status) ?>" <?= $currentStatus === $status ? 'checked' : '' ?> aria-label="<?= e($indicator['label']) ?> for <?= e((string) $student['full_name']) ?>"><span aria-hidden="true"><?= e($indicator['symbol']) ?></span></label></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <button class="button button-primary" type="submit">Save Attendance</button>
                    <p class="notice" data-attendance-sync-status role="status" aria-live="polite" hidden></p>
                    <ul class="notice notice-error" data-attendance-sync-conflicts aria-live="polite" hidden></ul>
                    <button class="button" type="button" data-attendance-sync-retry hidden>Sync attendance draft</button>
                    <button class="button button-quiet" type="button" data-attendance-sync-discard hidden>Discard draft and reload current register</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php if ($daywise && $selectedClass && $canEditDaily): ?>
    <script src="assets/js/attendance-sync.js?v=1" defer></script>
<?php endif; ?>
<?php render_footer(); ?>
