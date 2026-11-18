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
$actorId = (int) $user['id'];
$role = school_user_role_for_school($actorId, $schoolId);
$canManagePayroll = $role === 'school_proprietor' || $role === 'school_accountant';
if (active_it_support_context($user) !== null || !$canManagePayroll
    || !school_has_permission($user, $schoolId, 'payroll.manage')) {
    http_response_code(403);
    render_header('Payroll permission denied');
    echo '<section class="panel"><h1>Payroll permission denied</h1><p>Only the school Proprietor or Accountant can manage salary rates and payroll.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
    render_footer();
    exit;
}

$schemaCheck = db()->prepare(
    'SELECT
        (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (?, ?, ?))
      + (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?
           AND column_name IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?))
      + (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (?, ?, ?, ?)) AS schema_ready'
);
$schemaCheck->execute([
    'school_staff_salary_rates', 'monthly_allowance', 'monthly_salary', 'effective_from',
    'school_payroll_entries', 'base_salary', 'allowance_amount', 'gross_salary', 'net_salary',
    'attendance_required_days', 'attendance_elapsed_days', 'attendance_completed_days',
    'attendance_excused_days', 'attendance_makeup_days', 'attendance_pending_days',
    'attendance_outstanding_days', 'attendance_eligible', 'attendance_reviewed_at',
    'school_payroll_runs', 'payment_method', 'payment_reference', 'payment_note', 'paid_at',
]);
if ((int) $schemaCheck->fetchColumn() !== 20) {
    http_response_code(503);
    render_header('Payroll update required');
    echo '<section class="panel"><h1>Payroll update required</h1><p>Apply the salary, payroll payment, allowance, and attendance payroll migrations before using this page. No payroll or salary records have been changed.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
    render_footer();
    exit;
}

$attendanceTimezone = school_staff_attendance_timezone(db(), $schoolId);
$currentMonth = (new DateTimeImmutable('now', $attendanceTimezone))
    ->modify('first day of this month')
    ->format('Y-m-01');
$parseAmount = static function (mixed $value, string $field): string {
    $amount = trim((string) $value);
    if (preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $amount) !== 1
        || (float) $amount <= 0 || (float) $amount > 9999999999.99) {
        throw new RuntimeException('Enter a valid ' . $field . ' amount in GHS.');
    }
    return number_format((float) $amount, 2, '.', '');
};
$parseAllowance = static function (mixed $value): string {
    $amount = trim((string) $value);
    if (preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $amount) !== 1
        || (float) $amount > 9999999999.99) {
        throw new RuntimeException('Enter a valid monthly allowance in GHS, or leave it at zero.');
    }
    return number_format((float) $amount, 2, '.', '');
};
$returnToPayroll = static function (): never {
    redirect('staff_payroll.php');
};
$refreshPayrollAttendance = static function (
    int $staffId,
    string $staffRole,
    string $periodMonth
) use ($schoolId): array {
    $timezone = school_staff_attendance_timezone(db(), $schoolId);
    $now = new DateTimeImmutable('now', $timezone);
    $priorMonthsStmt = db()->prepare(
        'SELECT obligation_month
         FROM school_staff_attendance_makeup_obligations
         WHERE school_id = ? AND staff_id = ? AND obligation_month < ?
         ORDER BY obligation_month FOR UPDATE'
    );
    $priorMonthsStmt->execute([$schoolId, $staffId, $periodMonth]);
    foreach ($priorMonthsStmt->fetchAll(PDO::FETCH_COLUMN) as $priorMonth) {
        school_staff_attendance_refresh_makeup_obligation(
            db(),
            $schoolId,
            $staffId,
            $staffRole,
            (string) $priorMonth,
            $now
        );
    }

    $current = school_staff_attendance_refresh_makeup_obligation(
        db(),
        $schoolId,
        $staffId,
        $staffRole,
        $periodMonth,
        $now
    );
    $priorBalanceStmt = db()->prepare(
        'SELECT COALESCE(SUM(outstanding_days), 0)
         FROM school_staff_attendance_makeup_obligations
         WHERE school_id = ? AND staff_id = ? AND obligation_month < ?
           AND status = "open"'
    );
    $priorBalanceStmt->execute([$schoolId, $staffId, $periodMonth]);
    $priorOutstandingDays = (int) $priorBalanceStmt->fetchColumn();
    $current['carryover_days'] = $priorOutstandingDays;
    $current['outstanding_days'] += $priorOutstandingDays;
    $current['eligible'] = $current['eligible'] && $priorOutstandingDays === 0;
    return $current;
};
$refreshPayrollRunAttendance = static function (
    int $runId,
    string $periodMonth
) use ($schoolId, $refreshPayrollAttendance): int {
    $entriesStmt = db()->prepare(
        'SELECT entry.id, entry.staff_id, staff.role_name
         FROM school_payroll_entries entry
         JOIN school_staff staff
           ON staff.id = entry.staff_id AND staff.school_id = entry.school_id
         WHERE entry.payroll_run_id = ? AND entry.school_id = ?
           AND entry.attendance_reviewed_at IS NOT NULL
         ORDER BY entry.id FOR UPDATE'
    );
    $entriesStmt->execute([$runId, $schoolId]);
    $updateEntry = db()->prepare(
        'UPDATE school_payroll_entries
         SET attendance_required_days = ?, attendance_elapsed_days = ?,
             attendance_completed_days = ?, attendance_excused_days = ?,
             attendance_makeup_days = ?, attendance_pending_days = ?,
             attendance_outstanding_days = ?, attendance_eligible = ?,
             attendance_reviewed_at = NOW()
         WHERE id = ? AND school_id = ? AND payroll_run_id = ?'
    );
    $blocked = 0;
    foreach ($entriesStmt->fetchAll() as $entry) {
        $snapshot = $refreshPayrollAttendance(
            (int) $entry['staff_id'],
            (string) $entry['role_name'],
            $periodMonth
        );
        $updateEntry->execute([
            $snapshot['required_days'],
            $snapshot['elapsed_days'],
            $snapshot['completed_days'],
            $snapshot['excused_days'],
            $snapshot['credited_days'],
            $snapshot['pending_days'],
            $snapshot['outstanding_days'],
            $snapshot['eligible'] ? 1 : 0,
            (int) $entry['id'],
            $schoolId,
            $runId,
        ]);
        if (!$snapshot['eligible']) {
            $blocked++;
        }
    }
    return $blocked;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'set_salary') {
            $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
            $salary = $parseAmount($_POST['monthly_salary'] ?? '', 'monthly salary');
            $allowanceInput = trim((string) ($_POST['monthly_allowance'] ?? '0'));
            $allowance = $parseAllowance($allowanceInput === '' ? '0' : $allowanceInput);
            $effectiveFrom = trim((string) ($_POST['effective_from'] ?? ''));
            $effectiveDate = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($staffId === false || $staffId < 1
                || $effectiveDate === false || $effectiveDate->format('Y-m-d') !== $effectiveFrom
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $effectiveDate->format('d') !== '01' || $effectiveFrom < $currentMonth) {
                throw new RuntimeException('Choose an active staff member and a valid current or future month start for the salary.');
            }

            db()->beginTransaction();
            $staffStmt = db()->prepare(
                'SELECT staff.id
                 FROM school_staff staff
                 JOIN users account ON account.id = staff.user_id
                   AND account.status = "active" AND account.email_verified_at IS NOT NULL
                 WHERE staff.id = ? AND staff.school_id = ? AND staff.status = "active"
                   AND EXISTS (
                       SELECT 1 FROM school_memberships membership
                       WHERE membership.school_id = staff.school_id
                         AND membership.user_id = staff.user_id AND membership.status = "active"
                         AND membership.role_name NOT IN ("school_parent", "school_student")
                   )
                 LIMIT 1 FOR UPDATE'
            );
            $staffStmt->execute([(int) $staffId, $schoolId]);
            if (!$staffStmt->fetchColumn()) {
                throw new RuntimeException('That person is not an active, verified staff member of this school.');
            }
            $existingPayrollStmt = db()->prepare(
                'SELECT id FROM school_payroll_runs
                 WHERE school_id = ? AND period_month >= ? LIMIT 1 FOR UPDATE'
            );
            $existingPayrollStmt->execute([$schoolId, $effectiveFrom]);
            if ($existingPayrollStmt->fetchColumn()) {
                throw new RuntimeException('A payroll run already exists for the selected effective month or a later month. Keep its saved salary snapshot unchanged.');
            }
            $currentRateStmt = db()->prepare(
                'SELECT id, effective_from FROM school_staff_salary_rates
                 WHERE school_id = ? AND staff_id = ? AND status = "active"
                 LIMIT 1 FOR UPDATE'
            );
            $currentRateStmt->execute([$schoolId, (int) $staffId]);
            $currentRate = $currentRateStmt->fetch();
            if ($currentRate && $effectiveFrom <= (string) $currentRate['effective_from']) {
                throw new RuntimeException('A replacement salary must start after the current salary rate.');
            }
            if ($currentRate) {
                db()->prepare(
                    'UPDATE school_staff_salary_rates
                     SET status = "replaced", effective_to = DATE_SUB(?, INTERVAL 1 DAY)
                     WHERE id = ? AND school_id = ? AND status = "active"'
                )->execute([$effectiveFrom, (int) $currentRate['id'], $schoolId]);
            }
            db()->prepare(
                'INSERT INTO school_staff_salary_rates
                    (school_id, staff_id, monthly_salary, monthly_allowance, effective_from, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$schoolId, (int) $staffId, $salary, $allowance, $effectiveFrom, $actorId]);
            $rateId = (int) db()->lastInsertId();
            audit($actorId, 'school_staff_salary_rate_created',
                'school_id=' . $schoolId . ';staff_id=' . (int) $staffId . ';salary_rate_id=' . $rateId
                . ';monthly_salary=' . $salary . ';monthly_allowance=' . $allowance . ';effective_from=' . $effectiveFrom);
            db()->commit();
            flash('success', 'Monthly salary and allowance recorded. Earlier payroll entries remain unchanged.');
            $returnToPayroll();
        }

        if ($action === 'create_payroll') {
            db()->beginTransaction();
            $existingRun = db()->prepare(
                'SELECT id FROM school_payroll_runs
                 WHERE school_id = ? AND period_month = ? LIMIT 1 FOR UPDATE'
            );
            $existingRun->execute([$schoolId, $currentMonth]);
            if ($existingRun->fetchColumn()) {
                throw new RuntimeException('A payroll run already exists for this month.');
            }
            $eligibleStmt = db()->prepare(
                'SELECT staff.id AS staff_id, staff.role_name, rate.id AS salary_rate_id,
                        rate.monthly_salary, rate.monthly_allowance
                 FROM school_staff staff
                 JOIN users account ON account.id = staff.user_id
                   AND account.status = "active" AND account.email_verified_at IS NOT NULL
                 JOIN school_staff_salary_rates rate
                   ON rate.id = (
                       SELECT candidate.id
                       FROM school_staff_salary_rates candidate
                       WHERE candidate.school_id = staff.school_id
                         AND candidate.staff_id = staff.id
                         AND candidate.effective_from <= ?
                         AND (candidate.effective_to IS NULL OR candidate.effective_to >= ?)
                       ORDER BY candidate.effective_from DESC, candidate.id DESC LIMIT 1
                   )
                 WHERE staff.school_id = ? AND staff.status = "active"
                   AND EXISTS (
                       SELECT 1 FROM school_memberships membership
                       WHERE membership.school_id = staff.school_id
                         AND membership.user_id = staff.user_id AND membership.status = "active"
                         AND membership.role_name NOT IN ("school_parent", "school_student")
                   )
                 ORDER BY staff.id'
            );
            $eligibleStmt->execute([$currentMonth, $currentMonth, $schoolId]);
            $eligibleStaff = $eligibleStmt->fetchAll();
            if ($eligibleStaff === []) {
                throw new RuntimeException('No active staff members have an effective monthly salary for this month.');
            }
            $runStmt = db()->prepare(
                'INSERT INTO school_payroll_runs (school_id, period_month, created_by)
                 VALUES (?, ?, ?)'
            );
            try {
                $runStmt->execute([$schoolId, $currentMonth, $actorId]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('A payroll run already exists for this month.', 0, $exception);
                }
                throw $exception;
            }
            $runId = (int) db()->lastInsertId();
            $entryStmt = db()->prepare(
                'INSERT INTO school_payroll_entries
                    (school_id, payroll_run_id, staff_id, salary_rate_id, base_salary,
                     allowance_amount, gross_salary, net_salary, attendance_required_days,
                     attendance_elapsed_days, attendance_completed_days, attendance_excused_days,
                     attendance_makeup_days, attendance_pending_days, attendance_outstanding_days,
                     attendance_eligible, attendance_reviewed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            foreach ($eligibleStaff as $staffEntry) {
                $snapshot = $refreshPayrollAttendance(
                    (int) $staffEntry['staff_id'],
                    (string) $staffEntry['role_name'],
                    $currentMonth
                );
                $baseCents = (int) round((float) $staffEntry['monthly_salary'] * 100);
                $allowanceCents = (int) round((float) $staffEntry['monthly_allowance'] * 100);
                $grossCents = $baseCents + $allowanceCents;
                if ($grossCents < 1 || $grossCents > 999999999999) {
                    throw new RuntimeException('Payroll cannot be generated because salary plus allowance is outside the supported GHS amount.');
                }
                $gross = number_format($grossCents / 100, 2, '.', '');
                $entryStmt->execute([
                    $schoolId,
                    $runId,
                    (int) $staffEntry['staff_id'],
                    (int) $staffEntry['salary_rate_id'],
                    number_format($baseCents / 100, 2, '.', ''),
                    number_format($allowanceCents / 100, 2, '.', ''),
                    $gross,
                    $gross,
                    $snapshot['required_days'],
                    $snapshot['elapsed_days'],
                    $snapshot['completed_days'],
                    $snapshot['excused_days'],
                    $snapshot['credited_days'],
                    $snapshot['pending_days'],
                    $snapshot['outstanding_days'],
                    $snapshot['eligible'] ? 1 : 0,
                ]);
            }
            audit($actorId, 'school_payroll_run_created',
                'school_id=' . $schoolId . ';payroll_run_id=' . $runId . ';period_month=' . $currentMonth);
            db()->commit();
            flash('success', 'Draft payroll created for ' . (new DateTimeImmutable($currentMonth))->format('F Y') . '. Review and approve it before marking it paid.');
            $returnToPayroll();
        }

        if ($action === 'approve_payroll' || $action === 'pay_payroll') {
            $runId = filter_var($_POST['payroll_run_id'] ?? null, FILTER_VALIDATE_INT);
            if ($runId === false || $runId < 1) {
                throw new RuntimeException('Choose a valid payroll run.');
            }
            $paymentMethod = null;
            $paymentReference = null;
            $paymentNote = null;
            if ($action === 'pay_payroll') {
                $paymentMethod = (string) ($_POST['payment_method'] ?? '');
                $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
                $paymentNote = trim((string) ($_POST['payment_note'] ?? ''));
                if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'mobile_money', 'other'], true)) {
                    throw new RuntimeException('Choose the actual method used to pay this payroll.');
                }
                if (in_array($paymentMethod, ['bank_transfer', 'mobile_money'], true) && $paymentReference === '') {
                    throw new RuntimeException('Enter the transaction reference for a bank or mobile-money payroll payment.');
                }
                if (mb_strlen($paymentReference) > 100 || mb_strlen($paymentNote) > 500) {
                    throw new RuntimeException('Payment reference must be 100 characters or fewer and the note 500 characters or fewer.');
                }
            }
            db()->beginTransaction();
            $runStmt = db()->prepare(
                'SELECT id, period_month, status FROM school_payroll_runs
                 WHERE id = ? AND school_id = ? LIMIT 1 FOR UPDATE'
            );
            $runStmt->execute([(int) $runId, $schoolId]);
            $run = $runStmt->fetch();
            $expectedStatus = $action === 'approve_payroll' ? 'draft' : 'approved';
            if (!$run || $run['status'] !== $expectedStatus) {
                throw new RuntimeException('That payroll run is not in the required status for this action.');
            }
            $blockedCount = $refreshPayrollRunAttendance((int) $run['id'], (string) $run['period_month']);
            if ($blockedCount > 0) {
                audit($actorId, 'school_payroll_attendance_approval_blocked',
                    'school_id=' . $schoolId . ';payroll_run_id=' . (int) $run['id']
                    . ';staff_count=' . $blockedCount);
                db()->commit();
                flash('error', $blockedCount . ' staff member(s) still have scheduled days pending or outstanding attendance make-up obligations. Review the refreshed attendance snapshot and apply verified make-up credits before approval or payment.');
                $returnToPayroll();
            }
            $entryCountStmt = db()->prepare(
                'SELECT COUNT(*) FROM school_payroll_entries WHERE payroll_run_id = ? AND school_id = ?'
            );
            $entryCountStmt->execute([(int) $run['id'], $schoolId]);
            if ((int) $entryCountStmt->fetchColumn() < 1) {
                throw new RuntimeException('An empty payroll run cannot be approved or paid.');
            }
            if ($action === 'approve_payroll') {
                db()->prepare(
                    'UPDATE school_payroll_runs
                     SET status = "approved", approved_by = ?, approved_at = UTC_TIMESTAMP()
                     WHERE id = ? AND school_id = ? AND status = "draft"'
                )->execute([$actorId, (int) $run['id'], $schoolId]);
                audit($actorId, 'school_payroll_run_approved',
                    'school_id=' . $schoolId . ';payroll_run_id=' . (int) $run['id']);
                db()->commit();
                flash('success', 'Payroll approved. Mark it paid only after the actual staff payments are completed.');
                $returnToPayroll();
            }
            db()->prepare(
                'UPDATE school_payroll_runs
                 SET status = "paid", paid_by = ?, paid_at = UTC_TIMESTAMP(),
                     payment_method = ?, payment_reference = ?, payment_note = ?
                 WHERE id = ? AND school_id = ? AND status = "approved"'
            )->execute([
                $actorId, $paymentMethod, $paymentReference === '' ? null : $paymentReference,
                $paymentNote === '' ? null : $paymentNote, (int) $run['id'], $schoolId,
            ]);
            audit($actorId, 'school_payroll_run_paid',
                'school_id=' . $schoolId . ';payroll_run_id=' . (int) $run['id']
                . ';payment_method=' . $paymentMethod
                . ';payment_reference=' . ($paymentReference === '' ? 'none' : $paymentReference));
            db()->commit();
            flash('success', 'Payroll marked paid. This records the completed payroll payment; it does not transfer funds.');
            $returnToPayroll();
        }
        throw new RuntimeException('Choose a valid payroll action.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($exception instanceof PDOException) {
            throw $exception;
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
            $returnToPayroll();
        }
        throw $exception;
    }
}

$staffStmt = db()->prepare(
    'SELECT staff.id, account.full_name
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id
       AND account.status = "active" AND account.email_verified_at IS NOT NULL
     WHERE staff.school_id = ? AND staff.status = "active"
       AND EXISTS (
           SELECT 1 FROM school_memberships membership
           WHERE membership.school_id = staff.school_id
             AND membership.user_id = staff.user_id AND membership.status = "active"
             AND membership.role_name NOT IN ("school_parent", "school_student")
       )
     ORDER BY account.full_name, staff.id'
);
$staffStmt->execute([$schoolId]);
$staffMembers = $staffStmt->fetchAll();

$salaryCountStmt = db()->prepare('SELECT COUNT(*) FROM school_staff_salary_rates WHERE school_id = ?');
$salaryCountStmt->execute([$schoolId]);
$salaryCount = (int) $salaryCountStmt->fetchColumn();
$salaryPageSize = 50;
$salaryPageValue = filter_var($_GET['salary_page'] ?? 1, FILTER_VALIDATE_INT);
$salaryPageCount = max(1, (int) ceil($salaryCount / $salaryPageSize));
$salaryPage = min(max(1, $salaryPageValue === false ? 1 : (int) $salaryPageValue), $salaryPageCount);
$salaryStmt = db()->prepare(
    'SELECT rate.monthly_salary, rate.monthly_allowance, rate.effective_from,
            rate.effective_to, rate.status, account.full_name
     FROM school_staff_salary_rates rate
     JOIN school_staff staff ON staff.id = rate.staff_id AND staff.school_id = rate.school_id
     JOIN users account ON account.id = staff.user_id
     WHERE rate.school_id = ?
     ORDER BY (rate.status = "active") DESC, rate.effective_from DESC, account.full_name
     LIMIT ? OFFSET ?'
);
$salaryStmt->bindValue(1, $schoolId, PDO::PARAM_INT);
$salaryStmt->bindValue(2, $salaryPageSize, PDO::PARAM_INT);
$salaryStmt->bindValue(3, ($salaryPage - 1) * $salaryPageSize, PDO::PARAM_INT);
$salaryStmt->execute();
$salaryRates = $salaryStmt->fetchAll();

$runsStmt = db()->prepare(
    'SELECT run.id, run.period_month, run.status, run.created_at, run.paid_at,
            run.payment_method, run.payment_reference, run.payment_note,
            COUNT(entry.id) AS staff_count,
            COALESCE(SUM(entry.gross_salary), 0) AS gross_total,
            COALESCE(SUM(entry.net_salary), 0) AS net_total,
            COALESCE(SUM(entry.attendance_eligible = 0), 0) AS blocked_staff
     FROM school_payroll_runs run
     LEFT JOIN school_payroll_entries entry
       ON entry.payroll_run_id = run.id AND entry.school_id = run.school_id
     WHERE run.school_id = ?
     GROUP BY run.id
     ORDER BY run.period_month DESC, run.id DESC
     LIMIT 18'
);
$runsStmt->execute([$schoolId]);
$payrollRuns = $runsStmt->fetchAll();
$runEntries = [];
if ($payrollRuns !== []) {
    $runIds = array_map(static fn(array $run): int => (int) $run['id'], $payrollRuns);
    $placeholders = implode(',', array_fill(0, count($runIds), '?'));
    $entriesStmt = db()->prepare(
        'SELECT entry.payroll_run_id, entry.base_salary, entry.allowance_amount,
                entry.gross_salary, entry.net_salary, entry.attendance_required_days,
                entry.attendance_elapsed_days, entry.attendance_completed_days,
                entry.attendance_excused_days, entry.attendance_makeup_days,
                entry.attendance_pending_days, entry.attendance_outstanding_days,
                entry.attendance_eligible, account.full_name
         FROM school_payroll_entries entry
         JOIN school_staff staff ON staff.id = entry.staff_id AND staff.school_id = entry.school_id
         JOIN users account ON account.id = staff.user_id
         WHERE entry.school_id = ? AND entry.payroll_run_id IN (' . $placeholders . ')
         ORDER BY entry.payroll_run_id DESC, account.full_name'
    );
    $entriesStmt->execute(array_merge([$schoolId], $runIds));
    foreach ($entriesStmt->fetchAll() as $entry) {
        $runEntries[(int) $entry['payroll_run_id']][] = $entry;
    }
}

render_header('Staff payroll');
?>
<section class="panel">
    <p class="eyebrow">SCHOOL FINANCE · GHS</p>
    <h1>Staff payroll</h1>
    <p class="muted">Manage salary rates and prepare monthly payroll for <?= e((new DateTimeImmutable($currentMonth))->format('F Y')) ?>. Payroll totals are recorded after approval and payment; this page does not transfer funds.</p>
</section>

<section class="report-action-grid" aria-label="Payroll tools">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('finance') ?></span>
        <div><h2>Monthly payroll</h2><p>Review salary, allowance, attendance eligibility, and net pay before approval and payment.</p></div>
        <a class="button button-primary" href="#payroll-runs">View payroll runs</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('staff') ?></span>
        <div><h2>Salary rates</h2><p>Set monthly salary and allowance amounts with effective dates and history.</p></div>
        <a class="button" href="#salary-rates">Manage salary rates</a>
    </article>
</section>

<section class="panel spaced-top">
    <h2>Set a monthly salary</h2>
    <p class="muted">Salary changes take effect on the first day of a month. Previous rates and generated payroll snapshots are retained.</p>
    <?php if ($staffMembers === []): ?>
        <p class="muted">No eligible active staff members were found in this school.</p>
    <?php else: ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_salary">
            <label for="salary-staff">Active staff member</label>
            <select id="salary-staff" name="staff_id" required><option value="">Choose staff</option>
                <?php foreach ($staffMembers as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e((string) $member['full_name']) ?></option><?php endforeach; ?>
            </select>
            <label for="monthly-salary">Base monthly salary (GHS)</label>
            <input id="monthly-salary" name="monthly_salary" type="number" min="0.01" step="0.01" required>
            <label for="monthly-allowance">Monthly allowance (GHS, optional)</label>
            <input id="monthly-allowance" name="monthly_allowance" type="number" min="0" step="0.01" value="0">
            <label for="salary-effective-from">Effective from</label>
            <input id="salary-effective-from" name="effective_from" type="date" min="<?= e($currentMonth) ?>" value="<?= e($currentMonth) ?>" required>
            <button class="button button-primary" type="submit">Save salary rate</button>
        </form>
    <?php endif; ?>
</section>

<section class="panel spaced-top" id="salary-rates">
    <h2>Salary rate history</h2>
    <?php if ($salaryRates === []): ?><p class="muted">No salary rates have been recorded.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Staff member</th><th>Base salary</th><th>Allowance</th><th>Effective from</th><th>Effective to</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($salaryRates as $rate): ?>
                <tr>
                    <td><?= e((string) $rate['full_name']) ?></td>
                    <td>GHS <?= e(number_format((float) $rate['monthly_salary'], 2)) ?></td>
                    <td>GHS <?= e(number_format((float) $rate['monthly_allowance'], 2)) ?></td>
                    <td><?= e((string) $rate['effective_from']) ?></td>
                    <td><?= (string) $rate['effective_from'] > $currentMonth ? 'Scheduled' : ($rate['effective_to'] === null ? '—' : e((string) $rate['effective_to'])) ?></td>
                    <td><?= (string) $rate['effective_from'] > $currentMonth ? 'Scheduled' : ((string) $rate['status'] === 'active' ? 'Active' : 'Replaced') ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($salaryPageCount > 1): ?><nav class="directory-pagination" aria-label="Salary history pages">
            <span>Page <?= $salaryPage ?> of <?= $salaryPageCount ?> · <?= $salaryCount ?> rates</span>
            <?php foreach (['Previous' => $salaryPage - 1, 'Next' => $salaryPage + 1] as $label => $pageNumber):
                if ($pageNumber < 1 || $pageNumber > $salaryPageCount) { continue; }
                $query = $_GET;
                $query['salary_page'] = $pageNumber;
            ?><a class="button button-small" href="staff_payroll.php?<?= e(http_build_query($query)) ?>#salary-rates"><?= e($label) ?></a><?php endforeach; ?>
        </nav><?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel spaced-top" id="payroll-runs">
    <h2>Monthly payroll runs</h2>
    <p class="muted">Draft payroll can be approved only after attendance is eligible. Mark a run paid only after the staff payments have actually been completed.</p>
    <?php if ($canManagePayroll): ?>
        <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_payroll">
            <button class="button button-primary" type="submit" data-confirm="Create a draft salary payroll for <?= e($currentMonth) ?>?">Create this month's draft payroll</button>
        </form>
    <?php endif; ?>
    <?php if ($payrollRuns === []): ?><p class="muted">No payroll runs have been created.</p>
    <?php else: ?>
        <?php foreach ($payrollRuns as $run): ?>
            <?php $entries = $runEntries[(int) $run['id']] ?? []; ?>
            <article class="panel panel-top-gap">
                <h3><?= e((new DateTimeImmutable((string) $run['period_month']))->format('F Y')) ?> · <?= e(ucfirst((string) $run['status'])) ?></h3>
                <p><?= (int) $run['staff_count'] ?> staff · gross GHS <?= e(number_format((float) $run['gross_total'], 2)) ?> · net GHS <?= e(number_format((float) $run['net_total'], 2)) ?>
                    <?php if ((int) $run['blocked_staff'] > 0): ?> · <?= (int) $run['blocked_staff'] ?> attendance-blocked<?php endif; ?>
                </p>
                <div class="table-scroll"><table>
                    <thead><tr><th>Staff member</th><th>Base</th><th>Allowance</th><th>Gross / net</th><th>Attendance eligibility</th></tr></thead>
                    <tbody><?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?= e((string) $entry['full_name']) ?></td>
                            <td>GHS <?= e(number_format((float) $entry['base_salary'], 2)) ?></td>
                            <td>GHS <?= e(number_format((float) $entry['allowance_amount'], 2)) ?></td>
                            <td>GHS <?= e(number_format((float) $entry['gross_salary'], 2)) ?></td>
                            <td><?= (bool) $entry['attendance_eligible'] ? 'Eligible' : 'Not eligible' ?>
                                <br><small><?= (int) $entry['attendance_completed_days'] ?> completed · <?= (int) $entry['attendance_excused_days'] ?> excused · <?= (int) $entry['attendance_makeup_days'] ?> make-up · <?= (int) $entry['attendance_pending_days'] ?> pending · <?= (int) $entry['attendance_outstanding_days'] ?> outstanding</small>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if ($run['payment_method'] !== null): ?><p class="muted">Payment <?= e(str_replace('_', ' ', (string) $run['payment_method'])) ?><?= $run['payment_reference'] ? ' · reference ' . e((string) $run['payment_reference']) : '' ?><?= $run['payment_note'] ? ' · ' . e((string) $run['payment_note']) : '' ?> · <?= e((string) $run['paid_at']) ?></p><?php endif; ?>
                <?php if ((string) $run['status'] === 'draft'): ?>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?><input type="hidden" name="action" value="approve_payroll"><input type="hidden" name="payroll_run_id" value="<?= (int) $run['id'] ?>">
                        <button class="button button-primary" type="submit" data-confirm="Approve this payroll after reviewing the attendance snapshot?">Approve payroll</button>
                    </form>
                <?php elseif ((string) $run['status'] === 'approved'): ?>
                    <form method="post" class="form-grid two-columns">
                        <?= csrf_field() ?><input type="hidden" name="action" value="pay_payroll"><input type="hidden" name="payroll_run_id" value="<?= (int) $run['id'] ?>">
                        <label>Payment method<select name="payment_method" required><option value="">Choose method</option><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mobile_money">Mobile money</option><option value="other">Other</option></select></label>
                        <label>Reference (required for bank/mobile money)<input name="payment_reference" maxlength="100"></label>
                        <label class="span-all">Payment note (optional)<input name="payment_note" maxlength="500"></label>
                        <div class="span-all"><button class="button button-primary" type="submit" data-confirm="Confirm the actual payroll payment has been completed?">Confirm payroll paid</button></div>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
