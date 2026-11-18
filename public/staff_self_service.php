<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    flash('error', 'No active school was found for your account.');
    redirect('schools.php');
}
$schoolId = (int) $school['id'];
$userId = (int) $user['id'];
if (active_it_support_context($user) !== null) {
    http_response_code(403);
    render_header('Staff self-service unavailable');
    echo '<section class="panel"><h1>Staff self-service unavailable</h1><p>Staff self-service is unavailable during IT support access.</p></section>';
    render_footer();
    exit;
}

$staffStmt = db()->prepare(
    'SELECT staff.id, staff.employee_number, staff.role_name, staff.created_at
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id
       AND account.status = "active" AND account.email_verified_at IS NOT NULL
     WHERE staff.school_id = ? AND staff.user_id = ? AND staff.status = "active"
       AND EXISTS (
           SELECT 1 FROM school_memberships membership
           WHERE membership.school_id = staff.school_id
             AND membership.user_id = staff.user_id
             AND membership.status = "active"
             AND membership.role_name NOT IN ("school_parent", "school_student")
       )
     LIMIT 1'
);
$staffStmt->execute([$schoolId, $userId]);
$staffMember = $staffStmt->fetch();
if (!$staffMember) {
    http_response_code(403);
    render_header('Staff self-service access denied');
    echo '<section class="panel"><h1>Staff self-service access denied</h1><p>Your account is not linked to an active staff record in this school.</p><a class="button" href="school_dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}
$staffId = (int) $staffMember['id'];
$attendanceTimezone = school_staff_attendance_timezone(db(), $schoolId);
$attendanceNow = new DateTimeImmutable('now', $attendanceTimezone);
$attendanceToday = $attendanceNow->format('Y-m-d');
$attendanceSchedule = school_staff_attendance_schedule(db(), $schoolId, (string) $staffMember['role_name']);
$todayStmt = db()->prepare(
    'SELECT id, arrival_at, departure_at, status, punctuality_status, minutes_late, verification_method
     FROM school_staff_attendance attendance
     WHERE school_id = ? AND staff_id = ? AND attendance_date = ? LIMIT 1'
);
$todayStmt->execute([$schoolId, $staffId, $attendanceToday]);
$todayAttendance = $todayStmt->fetch() ?: null;
$attendanceRecordsSql = 'SELECT attendance.id, attendance.attendance_date, attendance.arrival_at,
                                attendance.departure_at, attendance.status,
                                attendance.punctuality_status, attendance.minutes_late,
                                attendance.verification_method,
                                EXISTS (
                                    SELECT 1 FROM school_staff_attendance_makeup_credits credit
                                    WHERE credit.school_id = attendance.school_id
                                      AND credit.attendance_id = attendance.id
                                ) AS makeup_credit
                         FROM school_staff_attendance attendance
                         WHERE attendance.school_id = ? AND attendance.staff_id = ?
                         ORDER BY attendance.attendance_date DESC LIMIT 30';
$attendanceRecordsStmt = db()->prepare($attendanceRecordsSql);
$attendanceRecordsStmt->execute([$schoolId, $staffId]);
$attendanceRecords = $attendanceRecordsStmt->fetchAll();
$attendanceTime = static function (?string $value) use ($attendanceTimezone): string {
    return $value === null ? '—' : (new DateTimeImmutable($value, $attendanceTimezone))->format('g:i A');
};

$message = '';
$messageKind = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'submit_dispute') {
            $attendanceId = filter_var($_POST['attendance_id'] ?? null, FILTER_VALIDATE_INT);
            $disputeMessage = trim((string) ($_POST['message'] ?? ''));
            if ($disputeMessage === '' || mb_strlen($disputeMessage) > 1000) {
                throw new RuntimeException('Enter a dispute message of up to 1000 characters.');
            }
            if ($attendanceId === false || $attendanceId < 1) {
                $attendanceId = null;
            } else {
                $ownedRecord = db()->prepare(
                    'SELECT id FROM school_staff_attendance
                     WHERE id = ? AND school_id = ? AND staff_id = ?'
                );
                $ownedRecord->execute([$attendanceId, $schoolId, $staffId]);
                if (!$ownedRecord->fetchColumn()) {
                    throw new RuntimeException('Select one of your own attendance records.');
                }
            }
            db()->beginTransaction();
            db()->prepare(
                'INSERT INTO school_staff_attendance_disputes
                    (school_id, staff_id, attendance_id, submitted_by, message)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$schoolId, $staffId, $attendanceId, $userId, $disputeMessage]);
            audit($userId, 'school_staff_attendance_dispute_submitted',
                'school_id=' . $schoolId . ';staff_id=' . $staffId);
            db()->commit();
            flash('success', 'Your attendance dispute has been sent to the school administrator.');
            redirect('staff_self_service.php#my-disputes');
        }
        throw new RuntimeException('Choose a valid attendance action.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($exception instanceof PDOException) {
            throw $exception;
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
            redirect('staff_self_service.php#my-attendance');
        }
        throw $exception;
    }
}

$currentDate = $attendanceToday;
$salaryStmt = db()->prepare(
    'SELECT monthly_salary, monthly_allowance, effective_from
     FROM school_staff_salary_rates
     WHERE school_id = ? AND staff_id = ? AND effective_from <= ?
       AND (effective_to IS NULL OR effective_to >= ?)
     ORDER BY effective_from DESC, id DESC LIMIT 1'
);
$salaryStmt->execute([$schoolId, $staffId, $currentDate, $currentDate]);
$currentSalary = $salaryStmt->fetch();
$payrollCountStmt = db()->prepare(
    'SELECT COUNT(*) FROM school_payroll_entries WHERE school_id = ? AND staff_id = ?'
);
$payrollCountStmt->execute([$schoolId, $staffId]);
$payrollCount = (int) $payrollCountStmt->fetchColumn();
$payrollPageSize = 24;
$payrollPageValue = filter_var($_GET['payroll_page'] ?? 1, FILTER_VALIDATE_INT);
$payrollPageCount = max(1, (int) ceil($payrollCount / $payrollPageSize));
$payrollPage = min(max(1, $payrollPageValue === false ? 1 : (int) $payrollPageValue), $payrollPageCount);
$payrollStmt = db()->prepare(
    'SELECT run.period_month, run.status, run.paid_at,
            run.payment_method, run.payment_reference,
            entry.base_salary, entry.allowance_amount, entry.gross_salary, entry.net_salary
     FROM school_payroll_entries entry
     JOIN school_payroll_runs run
       ON run.id = entry.payroll_run_id AND run.school_id = entry.school_id
     WHERE entry.school_id = ? AND entry.staff_id = ?
     ORDER BY run.period_month DESC, run.id DESC LIMIT ? OFFSET ?'
);
$payrollStmt->bindValue(1, $schoolId, PDO::PARAM_INT);
$payrollStmt->bindValue(2, $staffId, PDO::PARAM_INT);
$payrollStmt->bindValue(3, $payrollPageSize, PDO::PARAM_INT);
$payrollStmt->bindValue(4, ($payrollPage - 1) * $payrollPageSize, PDO::PARAM_INT);
$payrollStmt->execute();
$payrollHistory = $payrollStmt->fetchAll();
$disputesStmt = db()->prepare(
    'SELECT status, message, response, created_at
     FROM school_staff_attendance_disputes
     WHERE school_id = ? AND staff_id = ? AND submitted_by = ?
     ORDER BY created_at DESC LIMIT 20'
);
$disputesStmt->execute([$schoolId, $staffId, $userId]);
$disputes = $disputesStmt->fetchAll();
$renderPageLinks = static function (string $key, int $page, int $pageCount, string $fragment = ''): void {
    if ($pageCount <= 1) {
        return;
    }
    echo '<nav class="directory-pagination" aria-label="Payroll history pages"><span>Page '
        . $page . ' of ' . $pageCount . '</span>';
    foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $pageNumber) {
        if ($pageNumber < 1 || $pageNumber > $pageCount) {
            continue;
        }
        $query = $_GET;
        $query[$key] = $pageNumber;
        echo '<a class="button button-small" href="staff_self_service.php?'
            . e(http_build_query($query)) . ($fragment !== '' ? '#' . rawurlencode($fragment) : '')
            . '">' . e($label) . '</a>';
    }
    echo '</nav>';
};

render_header('My payroll and attendance');
?>
<section class="panel">
    <p class="eyebrow">MY STAFF RECORD · <?= e((string) $school['name']) ?></p>
    <h1>My staff records</h1>
    <?php if ($staffMember['employee_number']): ?><p class="muted">Employee number: <?= e((string) $staffMember['employee_number']) ?></p><?php endif; ?>
    <p class="muted">This page shows only your own attendance, salary, and payroll records for the selected school.</p>
    <p><a class="button" href="staff_records.php">View my staff concerns and records</a></p>
</section>

<section class="report-action-grid" aria-label="My staff workspace" data-action-return="staff_self_service.php?view=actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('attendance') ?></span>
        <div><h2>My attendance</h2><p>View today's status and your recent attendance register.</p></div>
        <a class="button button-primary" href="#my-attendance" data-action-open="my-attendance">Open attendance</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('finance') ?></span>
        <div><h2>My salary</h2><p>View your current monthly salary and allowance.</p></div>
        <a class="button" href="#my-salary" data-action-open="my-salary">View salary</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('receipt') ?></span>
        <div><h2>Payroll history</h2><p>Review and print your past payroll statements.</p></div>
        <a class="button" href="#my-payroll" data-action-open="my-payroll">View payroll</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('documents') ?></span>
        <div><h2>My attendance disputes</h2><p>Submit or follow up on a question about an attendance record.</p></div>
        <a class="button" href="#my-disputes" data-action-open="my-disputes">View disputes</a>
    </article>
</section>

<section class="panel top-gap" id="my-attendance" data-action-view="my-attendance" hidden>
    <h2>My attendance</h2>
    <?php
    $todayScheduled = school_staff_attendance_is_working_day(db(), $schoolId, $attendanceToday, $attendanceSchedule);
    $todayStatus = $todayAttendance === null
        ? ($todayScheduled ? 'Not recorded' : 'Not scheduled')
        : ucwords(str_replace('_', ' ', (string) ($todayAttendance['punctuality_status'] ?: $todayAttendance['status'])));
    ?>
    <div class="dashboard-grid">
        <article class="panel stat-card"><h3>Today · <?= e($attendanceToday) ?></h3><strong><?= e($todayStatus) ?></strong>
            <span class="muted">Check-in <?= e($attendanceTime($todayAttendance['arrival_at'] ?? null)) ?> · Check-out <?= e($attendanceTime($todayAttendance['departure_at'] ?? null)) ?></span>
        </article>
    </div>
    <h3>Recent daily records</h3>
    <?php if ($attendanceRecords === []): ?><p class="muted">No attendance records are available.</p>
    <?php else: ?>
        <div class="table-scroll"><table><thead><tr><th>Date</th><th>Day</th><th>Arrival</th><th>Status</th><th>Minutes late</th><th>Verification</th><th>Departure</th><th>Make-up credit</th><th>Dispute</th></tr></thead><tbody>
        <?php foreach ($attendanceRecords as $record): ?>
            <?php $date = (string) $record['attendance_date']; ?>
            <tr>
                <td><?= e($date) ?></td>
                <td><?= e((new DateTimeImmutable($date, $attendanceTimezone))->format('l')) ?></td>
                <td><?= e($attendanceTime($record['arrival_at'] ?? null)) ?></td>
                <td><?= e(ucwords(str_replace('_', ' ', (string) ($record['punctuality_status'] ?: $record['status'])))) ?><?= (bool) $record['makeup_credit'] ? ' · Make-up credit; excluded from ordinary totals' : '' ?></td>
                <td><?= (int) $record['minutes_late'] ?></td>
                <td><?= e(match ((string) $record['verification_method']) {
                    'kiosk_verified' => 'Kiosk verified',
                    'fingerprint_verified' => 'Fingerprint verified',
                    'system_generated' => 'System generated',
                    default => 'Administrator recorded',
                }) ?></td>
                <td><?= e($attendanceTime($record['departure_at'] ?? null)) ?></td>
                <td><?= (bool) $record['makeup_credit'] ? 'Credited to prior month' : '—' ?></td>
                <td><details><summary>Raise dispute</summary><form method="post" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="submit_dispute"><input type="hidden" name="attendance_id" value="<?= (int) $record['id'] ?>">
                    <label>Explain the issue<textarea name="message" rows="3" maxlength="1000" required></textarea></label><button class="button button-small" type="submit">Submit dispute</button>
                </form></details></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="panel top-gap" id="my-salary" data-action-view="my-salary" hidden>
    <h2>My salary</h2>
    <?php if (!$currentSalary): ?><p class="muted">No current salary rate is available.</p>
    <?php else: ?><p>Base monthly salary: <strong>GHS <?= e(number_format((float) $currentSalary['monthly_salary'], 2)) ?></strong>
        · Allowance: <strong>GHS <?= e(number_format((float) $currentSalary['monthly_allowance'], 2)) ?></strong>
        · Effective from <?= e((string) $currentSalary['effective_from']) ?></p><?php endif; ?>
</section>

<section class="panel top-gap" id="my-payroll" data-action-view="my-payroll" hidden>
    <h2>Payroll history</h2>
    <p class="muted">Print or save a copy of your own salary and payroll statement.</p>
    <button class="button button-primary" type="button" data-print-button>Print / Save statement</button>
    <?php if ($payrollHistory === []): ?><p class="muted">No payroll entries are available for your staff record.</p>
    <?php else: ?>
        <div class="table-scroll"><table><thead><tr><th>Salary period</th><th>Base salary</th><th>Allowance</th><th>Gross salary</th><th>Net salary</th><th>Status</th><th>Payment</th></tr></thead><tbody>
        <?php foreach ($payrollHistory as $entry): ?>
            <tr>
                <td><?= e((new DateTimeImmutable((string) $entry['period_month']))->format('F Y')) ?></td>
                <td>GHS <?= e(number_format((float) $entry['base_salary'], 2)) ?></td>
                <td>GHS <?= e(number_format((float) $entry['allowance_amount'], 2)) ?></td>
                <td>GHS <?= e(number_format((float) $entry['gross_salary'], 2)) ?></td>
                <td>GHS <?= e(number_format((float) $entry['net_salary'], 2)) ?></td>
                <td><?= e(ucfirst((string) $entry['status'])) ?></td>
                <td><?= $entry['paid_at'] === null ? 'Not recorded' : e((string) $entry['paid_at']) ?>
                    <?php if ($entry['payment_method'] !== null): ?><br><small><?= e(str_replace('_', ' ', (string) $entry['payment_method'])) ?></small><?php endif; ?>
                    <?php if ($entry['payment_reference'] !== null): ?><br><small>Ref <?= e((string) $entry['payment_reference']) ?></small><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php $renderPageLinks('payroll_page', $payrollPage, $payrollPageCount, 'my-payroll'); ?>
    <?php endif; ?>
</section>

<section class="panel top-gap" id="my-disputes" data-action-view="my-disputes" hidden>
    <h2>My attendance disputes</h2>
    <?php if ($disputes === []): ?><p class="muted">You have not submitted any disputes.</p>
    <?php else: ?><ul class="attendance-dispute-list">
        <?php foreach ($disputes as $dispute): ?><li><strong><?= e(ucfirst((string) $dispute['status'])) ?> · <?= e((string) $dispute['created_at']) ?></strong><p><?= e((string) $dispute['message']) ?></p><?php if ($dispute['response']): ?><p><strong>Administrator response:</strong> <?= e((string) $dispute['response']) ?></p><?php endif; ?></li><?php endforeach; ?>
    </ul><?php endif; ?>
</section>
<?php render_footer(); ?>
