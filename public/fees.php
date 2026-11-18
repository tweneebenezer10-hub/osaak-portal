<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_fees.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$pageAcademicPeriod = school_page_academic_period($schoolId);
$currentCalendarTerm = school_academic_period_for_date($schoolId);
$canManageFees = school_has_permission($user, $schoolId, 'fees.manage');
$canViewAssignedFees = school_has_permission($user, $schoolId, 'fees.view_assigned');
if (!$canManageFees && !$canViewAssignedFees) {
    require_school_permission($schoolId, 'fees.manage');
}

if (!$canManageFees) {
    $feeStatusSql = '
        SELECT sc.name AS class_name, sc.level AS class_level, sc.section AS class_section,
               sc.academic_year, ss.id AS student_id, ss.full_name, ss.admission_number,
               COALESCE(st.term_name, "No supported term configured") AS period_name,
               st.starts_on AS term_starts_on,
               (SELECT COUNT(*) FROM school_fee_structures fs
                WHERE fs.school_id = sc.school_id AND fs.class_level = sc.level
                  AND fs.academic_year = sc.academic_year AND fs.period_name = st.term_name
                  AND fs.is_active = 1 AND fs.effective_from <= st.starts_on) AS fee_schedule_count,
               si.id AS invoice_id, si.invoice_number, si.amount,
               si.balance, si.adjustments_total, si.status AS invoice_status,
               COALESCE(pay.verified_total, 0) AS verified_total,
               COALESCE(pay.pending_total, 0) AS pending_total,
               COALESCE(pay.mismatched_count, 0) AS mismatched_count,
               COALESCE(adj.approved_total, 0) AS approved_total
        FROM teacher_class_assignments tca
        JOIN school_classes sc ON sc.id = tca.class_id AND sc.school_id = tca.school_id
        JOIN school_students ss ON ss.school_id = sc.school_id AND ss.class_id = sc.id
                               AND ss.school_status = "active"
        LEFT JOIN school_academic_years active_year
          ON active_year.school_id = sc.school_id AND active_year.academic_year = sc.academic_year
        LEFT JOIN school_terms st ON st.school_id = active_year.school_id
                                 AND st.academic_year_id = active_year.id
                                 AND st.term_name IN ("First Term", "Second Term", "Third Term")
                                 AND st.id = ?
        LEFT JOIN school_invoices si
          ON si.school_id = ss.school_id AND si.student_id = ss.id
         AND si.academic_year = sc.academic_year AND si.class_level = sc.level
         AND si.period_name = st.term_name
        LEFT JOIN (
            SELECT allocation.school_id, allocation.invoice_id,
                   SUM(CASE WHEN payment.status = "verified" AND allocation.status = "active" THEN allocation.amount ELSE 0 END) AS verified_total,
                   SUM(CASE WHEN payment.status = "pending" AND allocation.status = "pending" THEN allocation.amount ELSE 0 END) AS pending_total,
                   SUM(CASE WHEN payment.student_id <> invoice.student_id THEN 1 ELSE 0 END) AS mismatched_count
            FROM school_payment_allocations allocation
            JOIN school_payments payment
              ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
             AND payment.student_id = allocation.student_id
            JOIN school_invoices invoice
              ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
             AND invoice.student_id = allocation.student_id
            WHERE allocation.school_id = ?
            GROUP BY allocation.school_id, allocation.invoice_id
        ) pay ON pay.school_id = si.school_id AND pay.invoice_id = si.id
        LEFT JOIN (
            SELECT school_id, invoice_id, SUM(amount) AS approved_total
            FROM school_invoice_adjustments
            WHERE school_id = ? AND status = "approved" AND adjustment_type <> "payment_arrangement"
            GROUP BY school_id, invoice_id
        ) adj ON adj.school_id = si.school_id AND adj.invoice_id = si.id
        WHERE tca.school_id = ? AND tca.teacher_user_id = ?
          AND tca.academic_year = sc.academic_year AND sc.status = "active"
        ORDER BY sc.academic_year DESC, sc.level, sc.section, ss.full_name, si.period_name';
    $feeStatusStmt = db()->prepare($feeStatusSql);
    $feeStatusStmt->execute([
        (int) ($currentCalendarTerm['term_id'] ?? 0),
        $schoolId,
        $schoolId,
        $schoolId,
        (int) $user['id'],
    ]);
    $feeStatuses = $feeStatusStmt->fetchAll();
    render_header('Class fee status');
    ?>
    <section class="panel" id="assigned-fee-status"><p class="eyebrow">CLASS TEACHER · READ ONLY</p><h1>Assigned-class fee status</h1>
        <p class="muted">This view shows invoice status only for students in your currently assigned classes. It cannot create invoices, record payments, or approve adjustments. Any mismatch between invoice balances and verified-payment/approved-adjustment records is flagged for financial review.</p>
    </section>
    <section class="report-action-grid" aria-label="Fee status actions">
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('receipt') ?></span>
            <div><h2>Assigned-Class Fee Status</h2><p>Review fee and payment status for students in your assigned classes.</p></div>
            <a class="button button-primary" href="#assigned-fee-status" data-action-open="assigned-fee-status">View Status</a>
        </article>
    </section>
    <?php if ($feeStatuses === []): ?>
        <section class="panel panel-top-gap" data-action-view="assigned-fee-status"><p class="muted">No active students or assigned classes are available.</p></section>
    <?php else: ?>
        <?php $lastClass = null; $classSummary = ['paid' => 0, 'partial' => 0, 'outstanding' => 0, 'review' => 0, 'not_assessed' => 0]; ?>
        <?php foreach ($feeStatuses as $feeStatus): ?>
            <?php
            $classKey = (string) $feeStatus['class_level'] . '|' . (string) $feeStatus['class_section'] . '|' . (string) $feeStatus['academic_year'];
            if ($classKey !== $lastClass):
                if ($lastClass !== null) {
                    echo '</tbody></table></div><p class="muted">Class summary: ' . $classSummary['paid'] . ' paid · '
                        . $classSummary['partial'] . ' partially paid · ' . $classSummary['outstanding']
                        . ' outstanding · ' . $classSummary['review'] . ' pending financial review · '
                        . $classSummary['not_assessed'] . ' not assessed</p></section>';
                }
                $lastClass = $classKey;
                $classSummary = ['paid' => 0, 'partial' => 0, 'outstanding' => 0, 'review' => 0, 'not_assessed' => 0];
            ?>
                <section class="panel panel-top-gap" data-action-view="assigned-fee-status"><h2><?= e(school_class_label(['level' => $feeStatus['class_level'], 'section' => $feeStatus['class_section']])) ?> · <?= e((string) $feeStatus['academic_year']) ?></h2>
                <div class="table-scroll"><table><thead><tr><th>Student</th><th>Admission number</th><th>Term</th><th>Invoice</th><th>Amount</th><th>Verified payments</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>
            <?php endif; ?>
            <?php
            $statusLabel = 'Not assessed';
            $statusKey = 'not_assessed';
            $balanceLabel = '—';
            $amountLabel = '—';
            $verifiedLabel = '—';
            if ($feeStatus['invoice_id'] !== null) {
                $amount = (float) $feeStatus['amount'];
                $balance = (float) $feeStatus['balance'];
                $verified = (float) $feeStatus['verified_total'];
                $approved = (float) $feeStatus['approved_total'];
                $recordedAdjustments = (float) $feeStatus['adjustments_total'];
                $consistent = abs(round($amount - $verified - $approved - $balance, 2)) < 0.01
                    && abs(round($recordedAdjustments - $approved, 2)) < 0.01
                    && (int) $feeStatus['mismatched_count'] === 0;
                $amountLabel = 'GHS ' . number_format($amount, 2);
                if ($consistent) {
                    $verifiedLabel = 'GHS ' . number_format($verified, 2);
                    $balanceLabel = 'GHS ' . number_format($balance, 2);
                    if ($balance <= 0.00001) {
                        $statusLabel = 'Paid';
                        $statusKey = 'paid';
                    } elseif ($verified > 0) {
                        $statusLabel = 'Partially paid';
                        $statusKey = 'partial';
                    } else {
                        $statusLabel = 'Outstanding';
                        $statusKey = 'outstanding';
                    }
                    if ((float) $feeStatus['pending_total'] > 0) $statusLabel .= ' · payment pending verification';
                } else {
                    $statusLabel = 'Pending financial review';
                    $statusKey = 'review';
                }
            } elseif ($feeStatus['term_starts_on'] !== null) {
                $statusLabel = (int) $feeStatus['fee_schedule_count'] === 0
                    ? 'Pending financial review · fee schedule missing'
                    : 'Pending financial review · invoice missing';
                $statusKey = 'review';
            }
            $classSummary[$statusKey]++;
            ?>
            <tr><td><?= e((string) $feeStatus['full_name']) ?></td><td><?= e((string) ($feeStatus['admission_number'] ?? '—')) ?></td><td><?= e((string) ($feeStatus['period_name'] ?? '—')) ?></td>
                <td><?= e((string) ($feeStatus['invoice_number'] ?? '—')) ?></td><td><?= e($amountLabel) ?></td>
                <td><?= e($verifiedLabel) ?></td><td><?= e($balanceLabel) ?></td><td><span class="status-pill <?= e(school_fee_status_class($statusKey)) ?>"><?= e($statusLabel) ?></span></td></tr>
        <?php endforeach; ?>
                <?php if ($lastClass !== null): ?></tbody></table></div><p class="muted">Class summary: <?= (int) $classSummary['paid'] ?> paid · <?= (int) $classSummary['partial'] ?> partially paid · <?= (int) $classSummary['outstanding'] ?> outstanding · <?= (int) $classSummary['review'] ?> pending financial review · <?= (int) $classSummary['not_assessed'] ?> not assessed</p></section><?php endif; ?>
    <?php endif; ?>
    <?php render_footer(); ?>
    <?php exit; ?>
<?php
}

function fee_notify_parents(int $schoolId, int $feeId, string $feeName, string $classLevel, string $academicYear, string $periodName, string $effectiveDate): void
{
    $stmt = db()->prepare(
        "SELECT DISTINCT u.id
         FROM school_students ss
         JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         JOIN school_guardians g ON g.id = ss.guardian_id AND g.school_id = ss.school_id
         JOIN users u ON LOWER(u.email) = LOWER(g.email) AND u.status = 'active' AND u.email_verified_at IS NOT NULL
         WHERE ss.school_id = ? AND sc.level = ?"
    );
    $stmt->execute([$schoolId, $classLevel]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $parentId) {
        create_user_notification(
            (int) $parentId,
            'school-fee-change-' . $feeId . '-' . $effectiveDate,
            'other',
            'School fee updated',
            $feeName . ' for ' . $classLevel . ', ' . $academicYear . ' ' . $periodName
                . ' has a new amount effective ' . $effectiveDate . '. Check the parent portal for details.',
            'parent_portal.php'
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_fee_structure') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $classLevel = trim((string) ($_POST['class_level'] ?? ''));
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        $category = (string) ($_POST['fee_category'] ?? 'other');
        $amountCents = school_fee_amount_cents($_POST['amount'] ?? null);
        $dueDay = filter_var($_POST['due_day'] ?? 10, FILTER_VALIDATE_INT);
        $effectiveFrom = trim((string) ($_POST['effective_from'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $levels = school_class_levels($schoolId);
        $categories = ['tuition', 'books', 'uniform', 'examination', 'other'];
        if ($name === '') {
            flash('error', 'Enter a name for the fee component.');
            redirect('fee_management.php?section=set-class-fees');
        }
        if (!in_array($classLevel, $levels, true)) {
            flash('error', 'Select a valid class level.');
            redirect('fee_management.php?section=set-class-fees');
        }
        if ($amountCents === null || $amountCents < 1) {
            flash('error', 'Enter a fee amount greater than zero with no more than two decimal places.');
            redirect('fees.php');
        }
        if (!in_array($category, $categories, true)) {
            flash('error', 'Select a valid fee category.');
            redirect('fees.php');
        }
        if ($dueDay === false || $dueDay < 1 || $dueDay > 31) {
            flash('error', 'Choose a due day between 1 and 31.');
            redirect('fees.php');
        }
        if (school_academic_year_dates($effectiveFrom, $effectiveFrom) === null) {
            flash('error', 'Enter a valid effective date for this fee schedule.');
            redirect('fees.php');
        }
        $term = null;
        if ($termId !== false && $termId > 0) {
            $termStmt = db()->prepare(
                'SELECT term.id, term.term_name, term.starts_on, term.ends_on,
                        academic_year.academic_year, academic_year.status AS year_status,
                        academic_year.starts_on AS year_starts_on, academic_year.ends_on AS year_ends_on
                 FROM school_terms term
                 JOIN school_academic_years academic_year
                   ON academic_year.id = term.academic_year_id AND academic_year.school_id = term.school_id
                 WHERE term.id = ? AND term.school_id = ? LIMIT 1'
            );
            $termStmt->execute([$termId, $schoolId]);
            $term = $termStmt->fetch() ?: null;
        }
        if ($term === null || !school_fee_supported_term((string) $term['term_name'])) {
            flash('error', 'Select a configured academic term belonging to this school. Review Academic Years & Terms if the term is missing.');
            redirect('fees.php');
        }
        if ($term['year_status'] !== 'open'
            || $term['starts_on'] < $term['year_starts_on'] || $term['ends_on'] > $term['year_ends_on']
            || school_academic_year_dates((string) $term['starts_on'], (string) $term['ends_on']) === null) {
            flash('error', 'The selected term is not valid within an open academic year. Review Academic Years & Terms.');
            redirect('fees.php');
        }
        if ($effectiveFrom > (string) $term['starts_on']) {
            flash('error', 'The fee schedule effective date must be on or before the selected term starts.');
            redirect('fees.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO school_fee_structures
             (school_id, name, amount, due_day, description, class_level, academic_year, period_name, fee_category, effective_from)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$schoolId, $name, number_format($amountCents / 100, 2, '.', ''), $dueDay, $description ?: null,
            $classLevel, (string) $term['academic_year'], (string) $term['term_name'], $category, $effectiveFrom]);
        audit((int) $user['id'], 'school_fee_structure_created', 'school_id=' . $schoolId . '; fee_id=' . db()->lastInsertId());
        flash('success', 'Fee component saved for this school, class, year, and period.');
        redirect('fees.php');
    }

    if ($action === 'adjust_fee') {
        $feeId = filter_var($_POST['fee_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['new_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $effectiveFrom = trim((string) ($_POST['effective_from'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($feeId === false || $feeId < 1 || $amount === false || $amount <= 0 || $reason === ''
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) || $effectiveFrom < date('Y-m-d')) {
            flash('error', 'Enter a valid future fee amount, effective date, and reason.');
            redirect('fees.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $feeStmt = $pdo->prepare('SELECT * FROM school_fee_structures WHERE id = ? AND school_id = ? FOR UPDATE');
            $feeStmt->execute([$feeId, $schoolId]);
            $fee = $feeStmt->fetch();
            if (!$fee) {
                throw new RuntimeException('The selected fee does not belong to this school.');
            }
            $previousAmount = (float) $fee['amount'];
            $currentStmt = $pdo->prepare(
                'SELECT new_amount FROM school_fee_adjustments WHERE school_id = ? AND fee_structure_id = ? ORDER BY effective_from DESC LIMIT 1'
            );
            $currentStmt->execute([$schoolId, $feeId]);
            $current = $currentStmt->fetchColumn();
            if ($current !== false) {
                $previousAmount = (float) $current;
            }
            $latestDateStmt = $pdo->prepare('SELECT MAX(effective_from) FROM school_fee_adjustments WHERE school_id = ? AND fee_structure_id = ?');
            $latestDateStmt->execute([$schoolId, $feeId]);
            $latestDate = $latestDateStmt->fetchColumn();
            if ($effectiveFrom < (string) $fee['effective_from'] || ($latestDate !== false && $latestDate !== null && $effectiveFrom <= (string) $latestDate)) {
                throw new RuntimeException('A fee adjustment must be later than the fee start date and all previously scheduled adjustments.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO school_fee_adjustments (school_id, fee_structure_id, previous_amount, new_amount, effective_from, reason, changed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$schoolId, $feeId, number_format($previousAmount, 2, '.', ''), number_format((float) $amount, 2, '.', ''),
                $effectiveFrom, $reason, (int) $user['id']]);
            $pdo->prepare('UPDATE school_fee_structures SET amount = ? WHERE id = ? AND school_id = ?')
                ->execute([number_format((float) $amount, 2, '.', ''), $feeId, $schoolId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('School fee adjustment failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The fee adjustment could not be saved.');
            redirect('fees.php');
        }
        audit((int) $user['id'], 'school_fee_adjusted', 'school_id=' . $schoolId . '; fee_id=' . $feeId . '; effective=' . $effectiveFrom . '; reason=' . $reason);
        fee_notify_parents($schoolId, $feeId, (string) $fee['name'], (string) $fee['class_level'],
            (string) $fee['academic_year'], (string) $fee['period_name'], $effectiveFrom);
        flash('success', 'Future fee amount updated and the change was recorded. Existing invoices were not modified.');
        redirect('fees.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $canManageFees) {
    redirect('fee_management.php');
}

$termsStmt = db()->prepare(
    'SELECT term.id, academic_year.academic_year, term.term_name, term.starts_on
     FROM school_terms term
     JOIN school_academic_years academic_year
       ON academic_year.id = term.academic_year_id AND academic_year.school_id = term.school_id
     WHERE term.school_id = ? AND academic_year.status = "open"
     ORDER BY term.starts_on DESC'
);
$termsStmt->execute([$schoolId]);
$terms = array_values(array_filter($termsStmt->fetchAll(), static fn(array $term): bool => school_fee_supported_term((string) $term['term_name'])));
$defaultFeeEffectiveFrom = '';
foreach ($terms as $availableTerm) {
    if ($pageAcademicPeriod !== null
        && $pageAcademicPeriod['academic_year'] === (string) $availableTerm['academic_year']
        && $pageAcademicPeriod['term_name'] === (string) $availableTerm['term_name']) {
        $defaultFeeEffectiveFrom = (string) $availableTerm['starts_on'];
        break;
    }
}
$feeStmt = db()->prepare(
    'SELECT id, name, amount, due_day, description, class_level, academic_year, period_name, fee_category, effective_from
     FROM school_fee_structures WHERE school_id = ? ORDER BY academic_year DESC, period_name, class_level, name'
);
$feeStmt->execute([$schoolId]);
$fees = $feeStmt->fetchAll();
$invoiceStmt = db()->prepare('SELECT COUNT(*) FROM school_invoices WHERE school_id = ?');
$invoiceStmt->execute([$schoolId]);
$invoiceCount = (int) $invoiceStmt->fetchColumn();

render_header('Fees');
?>
<section class="panel"><p class="eyebrow">FINANCE</p><h1>School fees</h1><p class="muted">Fee schedules support First Term, Second Term, and Third Term only, scoped by school, class level, and academic year.</p></section>
<section class="report-action-grid" aria-label="Fee management actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('receipt') ?></span>
        <div><h2>Add Fee Component</h2><p>Set a fee for a class level, academic year, term, and category.</p></div>
        <a class="button button-primary" href="#fee-component" data-action-open="fee-component">Add Component</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('calendar') ?></span>
        <div><h2>Review Fee Schedules</h2><p>Review existing schedules and manage future-dated fee adjustments.</p></div>
        <a class="button" href="#fee-schedules" data-action-open="fee-schedules">View Schedules</a>
    </article>
</section>
<section class="panel panel-top-gap" id="fee-component" data-action-view="fee-component">
    <h2>Add fee component</h2>
    <?php if ($terms === []): ?><p class="muted">No academic terms are configured for this school.<?php if (school_has_permission($user, $schoolId, 'school.manage')): ?> <a href="term_setup.php">Configure Academic Years &amp; Terms</a>.<?php else: ?> Ask your Headmaster or Proprietor to configure them.<?php endif; ?></p><?php else: ?>
    <form method="post" action="fees.php" class="form-stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_fee_structure">
        <div class="form-grid two-columns">
            <div><label for="name">Fee name</label><input id="name" name="name" maxlength="120" required></div>
            <div><label for="amount">Amount (GHS)</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" required></div>
            <div><label for="class_level">Class level</label><select id="class_level" name="class_level" required><option value="">Select class</option><?php foreach (school_class_levels($schoolId) as $level): ?><option value="<?= e($level) ?>"><?= e($level) ?></option><?php endforeach; ?></select></div>
            <div><label for="term_id">Academic year and term</label><select id="term_id" name="term_id" required><option value="">Select term</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $pageAcademicPeriod !== null && $pageAcademicPeriod['academic_year'] === (string) $term['academic_year'] && $pageAcademicPeriod['term_name'] === (string) $term['term_name'] ? 'selected' : '' ?>><?= e((string) $term['academic_year'] . ' · ' . (string) $term['term_name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="fee_category">Category</label><select id="fee_category" name="fee_category"><?php foreach (['tuition'=>'Tuition','books'=>'Books','uniform'=>'Uniform','examination'=>'Examination','other'=>'Other approved item'] as $value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div><label for="due_day">Due day of month</label><input id="due_day" name="due_day" type="number" min="1" max="31" value="10" required></div>
            <div><label for="effective_from">Effective from</label><input id="effective_from" name="effective_from" type="date" value="<?= e($defaultFeeEffectiveFrom) ?>" required></div>
            <div><label for="description">Description</label><input id="description" name="description" maxlength="255"></div>
        </div>
        <button class="button button-primary" type="submit">Save fee component</button>
    </form>
    <?php endif; ?>
</section>
<section class="panel panel-top-gap" id="fee-schedules" data-action-view="fee-schedules">
    <h2>Fee schedules</h2><p class="muted">Invoices retain the fee snapshot from when they were issued. Total invoices tracked: <?= $invoiceCount ?></p>
    <?php if ($fees === []): ?><p class="muted">No fee structure has been configured for this school yet.</p><?php else: ?>
    <table><thead><tr><th>Component</th><th>Class</th><th>Year / period</th><th>Category</th><th>Amount</th><th>Effective</th><th>Adjust future amount</th></tr></thead><tbody>
    <?php foreach ($fees as $fee): ?><tr>
        <td><?= e((string) $fee['name']) ?></td><td><?= e((string) $fee['class_level']) ?></td>
        <td><?= e((string) $fee['academic_year'] . ' / ' . (string) $fee['period_name']) ?></td><td><?= e((string) $fee['fee_category']) ?></td>
        <td>GHS <?= e(number_format((float) $fee['amount'], 2)) ?></td><td><?= e((string) $fee['effective_from']) ?></td>
        <td><form method="post" class="form-inline"><?= csrf_field() ?><input type="hidden" name="action" value="adjust_fee"><input type="hidden" name="fee_id" value="<?= (int) $fee['id'] ?>"><input name="new_amount" type="number" min="0.01" step="0.01" placeholder="New GHS amount" required><input name="effective_from" type="date" required><input name="reason" maxlength="500" placeholder="Reason" required><button class="button button-primary" type="submit">Apply</button></form></td>
    </tr><?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<?php render_footer(); ?>
