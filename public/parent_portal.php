<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_fees.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$smartTimetableReady = school_timetable_schema_ready();
require_school_permission($schoolId, 'students.view_own');
$canViewOwnFees = school_has_permission($user, $schoolId, 'fees.view_own');
$canViewOwnReports = school_has_permission($user, $schoolId, 'report_cards.view_own');
$canViewAnnouncements = school_has_permission($user, $schoolId, 'announcements.view');
$canViewOwnLearning = school_has_permission($user, $schoolId, 'learning.view_own');
$attendanceTerm = school_current_attendance_term($schoolId);
$attendanceTermLabel = (string) ($attendanceTerm['term_name'] ?? 'Current term');
$attendancePeriods = school_attendance_date_periods($attendanceTerm, null, $schoolId);

$emailVerified = !empty($user['email_verified_at']);
$children = [];
if ($emailVerified) {
    $childrenStmt = db()->prepare(
        'SELECT DISTINCT student.id, student.class_id, student.full_name, student.admission_number,
                class.level AS class_level, class.section AS class_section,
                class.academic_year
         FROM school_parent_links link
         JOIN school_students student
           ON student.id = link.student_id AND student.school_id = link.school_id
         JOIN school_guardians guardian
           ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
         JOIN users parent_account
           ON parent_account.id = link.user_id AND parent_account.status = "active"
              AND parent_account.email_verified_at IS NOT NULL
         JOIN school_memberships membership
           ON membership.user_id = link.user_id AND membership.school_id = link.school_id
              AND membership.role_name = "school_parent" AND membership.status = "active"
         LEFT JOIN school_classes class
           ON class.id = student.class_id AND class.school_id = student.school_id
         WHERE link.school_id = ? AND link.user_id = ? AND link.status = "verified"
           AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
         ORDER BY student.full_name'
    );
    $childrenStmt->execute([$schoolId, (int) $user['id']]);
    $children = $childrenStmt->fetchAll();
}

render_header('Parent portal');
?>
<?php render_school_calendar_preview($schoolId, $user); ?>
<section class="panel"><p class="eyebrow">PARENTS</p><h1>Parent and guardian portal</h1><p class="muted">View administrator-approved student links. Student and fee information are authorized independently.</p></section>
<?php if (!$emailVerified): ?><section class="panel" style="margin-top:1.5rem;"><p class="muted">Verify your account email to view administrator-approved linked children.</p></section><?php elseif ($children === []): ?><section class="panel" style="margin-top:1.5rem;"><p class="muted">No verified parent links are available for your account at this school. Contact the school office to review a link.</p></section><?php else: ?>
<?php foreach ($children as $child):
    $childId = (int) $child['id'];
    $leadershipStmt = db()->prepare(
        'SELECT position_title, academic_year, starts_on
         FROM school_student_leadership_assignments
         WHERE school_id = ? AND student_id = ? AND status = "active"
           AND starts_on <= UTC_DATE()
           AND (ends_on IS NULL OR ends_on >= UTC_DATE())
         ORDER BY academic_year DESC, position_title'
    );
    $leadershipStmt->execute([$schoolId, $childId]);
    $childLeadership = $leadershipStmt->fetchAll();
    $childAttendanceSummary = school_attendance_summaries(
        $schoolId,
        [$childId],
        $attendancePeriods,
    )[$childId] ?? [];

    $publishedResults = [];
    $publishedReportCards = [];
    if ($canViewOwnReports) {
        $resultsStmt = db()->prepare(
            'SELECT assessment.assessment_name, assessment.term_name, assessment.total_marks,
                    assessment.published_at, result_class.academic_year,
                    subject.name AS subject_name, ar.score, ar.grade
             FROM assessment_results ar
             JOIN school_assessments assessment
               ON assessment.id = ar.assessment_id AND assessment.school_id = ar.school_id
             JOIN school_classes result_class
               ON result_class.id = assessment.class_id AND result_class.school_id = assessment.school_id
             JOIN school_subjects subject
               ON subject.id = assessment.subject_id AND subject.school_id = assessment.school_id
             WHERE ar.school_id = ? AND ar.student_id = ?
               AND assessment.published_at IS NOT NULL
               AND EXISTS (
                   SELECT 1
                   FROM school_parent_links link
                   JOIN school_guardians guardian
                     ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
                   JOIN users parent_account
                     ON parent_account.id = link.user_id AND parent_account.status = "active"
                        AND parent_account.email_verified_at IS NOT NULL
                        AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
                   JOIN school_memberships membership
                     ON membership.user_id = link.user_id AND membership.school_id = link.school_id
                        AND membership.role_name = "school_parent" AND membership.status = "active"
                   WHERE link.school_id = ar.school_id AND link.student_id = ar.student_id
                     AND link.user_id = ? AND link.status = "verified"
               )
             ORDER BY assessment.published_at DESC, assessment.term_name, subject.name
             LIMIT 30'
        );
        $resultsStmt->execute([$schoolId, $childId, (int) $user['id']]);
        $publishedResults = $resultsStmt->fetchAll();

        $reportCardsStmt = db()->prepare(
            'SELECT batch.id, batch.term_name, batch.assessment_name, batch.academic_year,
                    batch.published_at
             FROM school_report_card_batches batch
             JOIN school_report_card_entries entry
               ON entry.batch_id = batch.id AND entry.school_id = batch.school_id
             WHERE batch.school_id = ? AND entry.student_id = ? AND batch.status = "published"
               AND EXISTS (
                   SELECT 1
                   FROM school_parent_links link
                   JOIN school_guardians guardian
                     ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
                   JOIN users parent_account
                     ON parent_account.id = link.user_id AND parent_account.status = "active"
                        AND parent_account.email_verified_at IS NOT NULL
                        AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
                   JOIN school_memberships membership
                     ON membership.user_id = link.user_id AND membership.school_id = link.school_id
                        AND membership.role_name = "school_parent" AND membership.status = "active"
                   WHERE link.school_id = entry.school_id AND link.student_id = entry.student_id
                     AND link.user_id = ? AND link.status = "verified"
               )
             ORDER BY batch.published_at DESC, batch.id DESC'
        );
        $reportCardsStmt->execute([$schoolId, $childId, (int) $user['id']]);
        $publishedReportCards = $reportCardsStmt->fetchAll();
    }

    $publishedTimetable = [];
    if ($canViewOwnLearning && $child['class_id'] !== null) {
        $timetableStmt = db()->prepare(
            'SELECT entry.weekday, entry.starts_at, entry.ends_at,
                    class.level, class.section,
                    subject.name AS subject_name
             FROM school_timetable_entries entry
             JOIN school_classes class
               ON class.id = entry.class_id AND class.school_id = entry.school_id
             JOIN school_students student
               ON student.school_id = entry.school_id AND student.class_id = entry.class_id
             LEFT JOIN school_subjects subject
               ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
             WHERE entry.school_id = ? AND student.id = ? AND student.school_status = "active"
               AND entry.published_at IS NOT NULL'
                . ($smartTimetableReady ? ' AND (
                   entry.version_id IS NULL
                   OR EXISTS (
                       SELECT 1 FROM school_timetable_versions effective
                       WHERE effective.id = entry.version_id AND effective.school_id = entry.school_id
                         AND effective.status = "published"
                         AND (effective.effective_from IS NULL OR effective.effective_from <= CURDATE())
                         AND (effective.effective_to IS NULL OR effective.effective_to >= CURDATE())
                   )
               )' : '') . '
               AND EXISTS (
                   SELECT 1
                   FROM school_parent_links link
                   JOIN school_guardians guardian
                     ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
                   JOIN users parent_account
                     ON parent_account.id = link.user_id AND parent_account.status = "active"
                        AND parent_account.email_verified_at IS NOT NULL
                        AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
                   JOIN school_memberships membership
                     ON membership.user_id = link.user_id AND membership.school_id = link.school_id
                        AND membership.role_name = "school_parent" AND membership.status = "active"
                   WHERE link.school_id = student.school_id AND link.student_id = student.id
                     AND link.user_id = ? AND link.status = "verified"
               )
             ORDER BY entry.weekday, entry.starts_at'
        );
        $timetableStmt->execute([$schoolId, $childId, (int) $user['id']]);
        $publishedTimetable = $timetableStmt->fetchAll();

    }

    $parentAnnouncements = [];
    if ($canViewAnnouncements) {
        $announcementsStmt = db()->prepare(
            'SELECT announcement.title, announcement.body, announcement.published_at
             FROM school_announcements announcement
             WHERE announcement.school_id = ? AND announcement.published_at IS NOT NULL
               AND announcement.audience IN ("all", "parents")
               AND EXISTS (
                   SELECT 1
                   FROM school_parent_links link
                   JOIN school_guardians guardian
                     ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
                   JOIN users parent_account
                     ON parent_account.id = link.user_id AND parent_account.status = "active"
                        AND parent_account.email_verified_at IS NOT NULL
                        AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
                   JOIN school_memberships membership
                     ON membership.user_id = link.user_id AND membership.school_id = link.school_id
                        AND membership.role_name = "school_parent" AND membership.status = "active"
                   WHERE link.school_id = announcement.school_id AND link.student_id = ?
                     AND link.user_id = ? AND link.status = "verified"
               )
             ORDER BY announcement.published_at DESC LIMIT 10'
        );
        $announcementsStmt->execute([$schoolId, $childId, (int) $user['id']]);
        $parentAnnouncements = $announcementsStmt->fetchAll();
    }

    $childInvoices = [];
    if ($canViewOwnFees) {
        $invoiceStmt = db()->prepare(
            'SELECT id, invoice_number, amount, balance, adjustments_total, status, due_date, class_level, academic_year, period_name, fee_snapshot
             FROM school_invoices WHERE school_id = ? AND student_id = ? ORDER BY created_at DESC'
        );
        $invoiceStmt->execute([$schoolId, $childId]);
        $childInvoices = $invoiceStmt->fetchAll();
    }
    $childAttendanceChart = [];
    if ($childAttendanceSummary !== []) {
        $attendanceChartSummary = $childAttendanceSummary['term']
            ?? $childAttendanceSummary['month']
            ?? null;
        if ($attendanceChartSummary !== null) {
            $childAttendanceChart = [
                'Present' => (int) ($attendanceChartSummary['present'] ?? 0),
                'Absent' => (int) ($attendanceChartSummary['absent'] ?? 0),
                'Not recorded' => (int) ($attendanceChartSummary['unmarked'] ?? 0),
            ];
        }
    }
    $childSubjectPerformance = [];
    $childTermPerformance = [];
    foreach ($publishedResults as $result) {
        $totalMarks = (float) $result['total_marks'];
        if ($totalMarks <= 0) {
            continue;
        }
        $subject = (string) $result['subject_name'];
        $childSubjectPerformance[$subject]['total'] = ($childSubjectPerformance[$subject]['total'] ?? 0)
            + ((float) $result['score'] / $totalMarks * 100);
        $childSubjectPerformance[$subject]['count'] = ($childSubjectPerformance[$subject]['count'] ?? 0) + 1;
        $termLabel = school_academic_year_label((string) $result['academic_year']) . ' · ' . (string) $result['term_name'];
        $childTermPerformance[$termLabel]['total'] = ($childTermPerformance[$termLabel]['total'] ?? 0)
            + ((float) $result['score'] / $totalMarks * 100);
        $childTermPerformance[$termLabel]['count'] = ($childTermPerformance[$termLabel]['count'] ?? 0) + 1;
    }
    $childSubjectPerformanceChart = [];
    foreach ($childSubjectPerformance as $subject => $performance) {
        $childSubjectPerformanceChart[$subject] = $performance['total'] / $performance['count'];
    }
    $childTermPerformanceChart = [];
    foreach (array_reverse($childTermPerformance, true) as $termLabel => $performance) {
        $childTermPerformanceChart[$termLabel] = $performance['total'] / $performance['count'];
    }
    $childOutstandingChart = [];
    $childInvoiceBalances = $childInvoices;
    usort($childInvoiceBalances, static fn (array $left, array $right): int => (float) $right['balance'] <=> (float) $left['balance']);
    foreach (array_slice($childInvoiceBalances, 0, 8) as $invoice) {
        $childOutstandingChart[(string) $invoice['invoice_number']] = (float) $invoice['balance'];
    }
?>
<section class="panel parent-child-card">
<details class="parent-child-details">
<summary>
    <strong><?= e((string) $child['full_name']) ?></strong>
    <span>Admission: <?= e((string) ($child['admission_number'] ?: '—')) ?> · Class: <?= e($child['class_level'] ? school_class_label(['level'=>$child['class_level'],'section'=>$child['class_section']]) : 'Unassigned') ?> · Academic year: <?= e($child['academic_year'] ? school_academic_year_label((string) $child['academic_year']) : 'Not assigned') ?></span>
    <small class="parent-child-toggle"><span class="parent-child-open-label">View child records</span><span class="parent-child-close-label">Hide child records</span></small>
</summary>
<div class="parent-child-content">
<?php if ($childLeadership !== []): ?><h4>Student leadership</h4><ul><?php foreach ($childLeadership as $position): ?><li><strong><?= e((string) $position['position_title']) ?></strong> · <?= e(school_academic_year_label((string) $position['academic_year'])) ?> · appointed <?= e((string) $position['starts_on']) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($childAttendanceSummary !== []): ?><h4>Attendance summary</h4><p class="muted">Blank attendance is Not Recorded, not an absence. Percentages are provisional until all scheduled attendance is recorded.</p><p class="attendance-legend"><span class="attendance-present">✓ Present</span><span class="attendance-absent">✕ Absent</span><span class="attendance-unmarked">· Not recorded</span></p><div class="table-scroll"><table><thead><tr><th>Period</th><th class="attendance-present">✓ Present</th><th class="attendance-absent">✕ Absent</th><th>Not recorded</th><th>Attendance %</th></tr></thead><tbody>
<?php foreach ($childAttendanceSummary as $periodKey => $summary): ?><tr><th><?= e((string) ($periodKey === 'term' ? $attendanceTermLabel : ($periodKey === 'year' ? 'Academic year ' . ($attendanceTerm['academic_year'] ?? '') : ($periodKey === 'week' ? 'This week' : 'This month')))) ?></th><td><span class="attendance-present"><?= (int) $summary['present'] ?>/<?= (int) $summary['scheduled_days'] ?></span></td><td><span class="attendance-absent"><?= (int) $summary['absent'] ?></span></td><td><?= (int) $summary['unmarked'] ?></td><td><?= $summary['percentage'] === null ? 'Provisional' : e(number_format((float) $summary['percentage'], 2)) . '%' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php render_dashboard_bar_chart('Attendance records · ' . $attendanceTermLabel, $childAttendanceChart, 'days'); ?><?php endif; ?>
<h4>Published results and report cards</h4>
<?php if (!$canViewOwnReports): ?><p class="muted">Your account does not have permission to view published results or report cards.</p>
<?php else: ?>
<p><a class="button" href="report_cards.php?student_id=<?= $childId ?>">Open this child’s published report cards</a></p>
<?php if ($publishedResults === []): ?><p class="muted">No assessment results have been published for this child.</p><?php else: ?>
<div class="table-scroll"><table><thead><tr><th>Assessment</th><th>Term</th><th>Subject</th><th>Score</th><th>Grade</th><th>Published</th></tr></thead><tbody>
<?php foreach ($publishedResults as $result): ?><tr><td><?= e((string) $result['assessment_name']) ?></td><td><?= e((string) $result['term_name']) ?></td><td><?= e((string) $result['subject_name']) ?></td><td><?= e(number_format((float) $result['score'], 2)) ?> / <?= e(number_format((float) $result['total_marks'], 2)) ?></td><td><?= e((string) ($result['grade'] ?: '—')) ?></td><td><?= e((string) $result['published_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php render_dashboard_bar_chart('Average published score by subject · recent records', $childSubjectPerformanceChart, '%'); ?><?php if (count($childTermPerformanceChart) > 1): ?><p class="muted">Term comparison uses the latest 30 published assessment results available for this child.</p><?php render_dashboard_line_chart('Recent published average by academic term', $childTermPerformanceChart, '%'); ?><?php endif; ?><?php endif; ?>
<?php if ($publishedReportCards !== []): ?><h5>Published report-card batches</h5><ul><?php foreach ($publishedReportCards as $reportCard): ?><li><a href="report_cards.php?student_id=<?= $childId ?>&amp;batch_id=<?= (int) $reportCard['id'] ?>"><?= e((string) $reportCard['term_name']) ?> · <?= e((string) $reportCard['assessment_name']) ?> · <?= e((string) $reportCard['academic_year']) ?></a> · published <?= e((string) $reportCard['published_at']) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>
<h4>Published timetable</h4>
<?php if (!$canViewOwnLearning): ?><p class="muted">Your account does not have permission to view class learning information.</p>
<?php elseif ($publishedTimetable === []): ?><p class="muted">No published timetable is available for this child’s class.</p><?php else: ?>
<div class="table-scroll"><table><thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Venue</th></tr></thead><tbody>
<?php foreach ($publishedTimetable as $entry): ?><tr><td><?= e([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][(int) $entry['weekday']] ?? '—') ?></td><td><?= e(substr((string) $entry['starts_at'], 0, 5)) ?>–<?= e(substr((string) $entry['ends_at'], 0, 5)) ?></td><td><?= e((string) ($entry['subject_name'] ?: 'Class period')) ?></td><td><?= e(school_class_label(['level' => $entry['level'], 'section' => $entry['section']])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<h4>Published school announcements</h4>
<?php if (!$canViewAnnouncements): ?><p class="muted">Your account does not have permission to view school announcements.</p>
<?php elseif ($parentAnnouncements === []): ?><p class="muted">No published announcements for parents are available.</p>
<?php else: ?><ul><?php foreach ($parentAnnouncements as $announcement): ?><li><strong><?= e((string) $announcement['title']) ?></strong> · <?= e((string) $announcement['published_at']) ?><?php render_dashboard_read_more((string) $announcement['body']); ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!$canViewOwnFees): ?><p class="muted">Your account does not have permission to view student fee records.</p><?php else: ?>
<?php if ($childInvoices === []): ?><p class="muted">No invoices are available for this student.</p><?php else:
    $childCreditStmt = db()->prepare(
        'SELECT COALESCE(SUM(credit.surplus_amount - COALESCE(used.amount, 0)), 0)
         FROM school_fee_credit_sources credit
         JOIN school_payments payment
           ON payment.id = credit.payment_id AND payment.school_id = credit.school_id
          AND payment.student_id = credit.student_id AND payment.status = "verified"
         LEFT JOIN (
             SELECT payment_id, SUM(amount) AS amount
             FROM school_payment_allocations
             WHERE school_id = ? AND student_id = ? AND allocation_type = "automatic_credit" AND status = "active"
             GROUP BY payment_id
         ) used ON used.payment_id = credit.payment_id
         WHERE credit.school_id = ? AND credit.student_id = ?'
    );
    $childCreditStmt->execute([$schoolId, (int) $child['id'], $schoolId, (int) $child['id']]);
    $childAvailableCredit = (float) $childCreditStmt->fetchColumn();
?>
<?php if ($childAvailableCredit > 0): ?><p><strong>Verified surplus credit available for future terms:</strong> GHS <?= e(number_format($childAvailableCredit, 2)) ?></p><?php endif; ?>
<div class="table-scroll"><table><thead><tr><th>Invoice / period</th><th>Fee items</th><th>Amount</th><th>Approved adjustments</th><th>Outstanding</th><th>Status / due</th><th>Verified payments and receipts</th></tr></thead><tbody>
<?php foreach ($childInvoices as $invoice):
    $itemData = json_decode((string) ($invoice['fee_snapshot'] ?? ''), true);
    $adjustmentStmt = db()->prepare(
        'SELECT adjustment_type, amount, valid_until, reason
         FROM school_invoice_adjustments
         WHERE school_id = ? AND invoice_id = ? AND status = "approved" ORDER BY approved_at'
    );
    $adjustmentStmt->execute([$schoolId, (int) $invoice['id']]);
    $approvedAdjustments = $adjustmentStmt->fetchAll();
    $paymentStmt = db()->prepare(
        'SELECT payment.id, allocation.amount AS allocated_amount, allocation.allocation_type,
                payment.payment_coverage,
                payment.payment_method, payment.payment_reference, payment.received_at
         FROM school_payment_allocations allocation
         JOIN school_payments payment
           ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
          AND payment.student_id = allocation.student_id
         WHERE allocation.school_id = ? AND allocation.invoice_id = ? AND allocation.student_id = ?
           AND allocation.status = "active" AND payment.status = "verified"
         ORDER BY payment.received_at DESC'
    );
    $paymentStmt->execute([$schoolId, (int) $invoice['id'], (int) $child['id']]);
    $verifiedPayments = $paymentStmt->fetchAll();
?>
<tr><td><?= e((string) $invoice['invoice_number'] . ' · ' . (string) ($invoice['academic_year'] ?: 'Legacy') . ' ' . (string) ($invoice['period_name'] ?: '')) ?></td>
<td><?php if (is_array($itemData)): foreach ($itemData as $item): ?><?= e((string) ($item['name'] ?? 'Fee')) ?>: GHS <?= e((string) ($item['amount'] ?? '0.00')) ?><br><?php endforeach; else: ?>Historical invoice<?php endif; ?></td>
<td>GHS <?= e(number_format((float) $invoice['amount'], 2)) ?></td><td>GHS <?= e(number_format((float) $invoice['adjustments_total'], 2)) ?><?php foreach ($approvedAdjustments as $adjustment): ?><br><?= e((string) $adjustment['adjustment_type']) ?><?= $adjustment['amount'] !== null ? ' · GHS ' . e(number_format((float) $adjustment['amount'], 2)) : ' · until ' . e((string) ($adjustment['valid_until'] ?: '—')) ?><?= $adjustment['reason'] !== '' ? ' · ' . e((string) $adjustment['reason']) : '' ?><?php endforeach; ?></td>
<td>GHS <?= e(number_format((float) $invoice['balance'], 2)) ?></td><td><span class="status-pill <?= e(school_fee_status_class((string) $invoice['status'])) ?>"><?= e(ucfirst((string) $invoice['status'])) ?></span><?= $invoice['due_date'] ? ' · due ' . e((string) $invoice['due_date']) : '' ?></td>
<td><?php if ($verifiedPayments === []): ?>No verified allocations<?php else: foreach ($verifiedPayments as $payment): ?>GHS <?= e(number_format((float) $payment['allocated_amount'], 2)) ?> allocated · <?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?> · <?= e(str_replace('_', ' ', (string) $payment['allocation_type'])) ?> · <?= e((string) $payment['payment_method']) ?> · <?= e((string) $payment['received_at']) ?> <a href="school_payment_receipt.php?id=<?= (int) $payment['id'] ?>">Receipt</a><br><?php endforeach; endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php render_dashboard_bar_chart('Outstanding balance · highest invoices', $childOutstandingChart, 'GHS'); ?><?php endif; ?>
<?php endif; ?>
</div>
</details>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
