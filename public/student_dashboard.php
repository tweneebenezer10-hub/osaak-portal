<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_fees.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    flash('error', 'The selected school is not available.');
    redirect('platform_admin.php');
}
$schoolId = (int) $school['id'];
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$smartTimetableReady = school_timetable_schema_ready();
$studentPreview = !empty($user['it_support_mode'])
    && !empty($user['student_preview_mode'])
    && $schoolRole === 'school_student';
if ($schoolRole !== 'school_student' || (!$studentPreview && !school_has_permission($user, $schoolId, 'student.portal.view'))) {
    http_response_code(403);
    render_header('Student dashboard unavailable');
    echo '<section class="panel"><h1>Student dashboard unavailable</h1><p>This dashboard is for an approved student account or an active Student preview session.</p><a class="button button-primary" href="school_dashboard.php">Return to your school dashboard</a></section>';
    render_footer();
    exit;
}
require_school_permission($schoolId, 'student.portal.view');

$studentsStmt = db()->prepare(
    'SELECT ss.id, ss.class_id, ss.full_name, ss.admission_number, ss.date_of_birth, ss.gender,
            ss.school_status, sc.level AS class_level, sc.section AS class_section,
            sc.academic_year
     FROM school_students ss
     LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
     WHERE ss.school_id = ? AND ss.school_status = "active"
     ORDER BY ss.full_name, ss.id'
);
$students = [];
$student = null;
$selectedStudentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);
if ($studentPreview) {
    $studentsStmt->execute([$schoolId]);
    $students = $studentsStmt->fetchAll();
    foreach ($students as $candidate) {
        if ($selectedStudentId !== false && (int) $candidate['id'] === $selectedStudentId) {
            $student = $candidate;
            break;
        }
    }
    if ($student === null && $students !== []) {
        $student = $students[0];
    }
} else {
    $studentStmt = db()->prepare(
        'SELECT ss.id, ss.class_id, ss.full_name, ss.admission_number, ss.date_of_birth, ss.gender,
                ss.school_status, sc.level AS class_level, sc.section AS class_section,
                sc.academic_year
         FROM school_student_account_links link
         JOIN school_students ss ON ss.id = link.student_id AND ss.school_id = link.school_id
         LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         WHERE link.school_id = ? AND link.user_id = ? AND link.status = "active"
           AND ss.school_status = "active"
         LIMIT 1'
    );
    $studentStmt->execute([$schoolId, (int) $user['id']]);
    $student = $studentStmt->fetch() ?: null;
    if ($student === null) {
        http_response_code(403);
        render_header('Student record unavailable');
        echo '<section class="panel"><h1>Student record unavailable</h1><p>Your school account is not linked to an active, approved student record. Contact the school office to verify your account.</p></section>';
        render_footer();
        exit;
    }
}

$attendance = [];
$attendanceSummary = ['present' => 0, 'absent' => 0];
$attendanceTerm = school_current_attendance_term($schoolId);
$attendanceTermLabel = (string) ($attendanceTerm['term_name'] ?? 'No active term is scheduled');
$attendancePeriods = school_attendance_date_periods($attendanceTerm, null, $schoolId);
$attendanceSummaryPeriods = [];
$results = [];
$invoices = [];
$payments = [];
$announcements = [];
$timetable = [];
$homeworkAssignments = [];
$notifications = [];
$leadershipPositions = [];
if ($student !== null) {
    $studentId = (int) $student['id'];
    $leadershipStmt = db()->prepare(
        'SELECT position_title, academic_year, starts_on, ends_on
         FROM school_student_leadership_assignments
         WHERE school_id = ? AND student_id = ? AND status = "active"
           AND starts_on <= UTC_DATE()
           AND (ends_on IS NULL OR ends_on >= UTC_DATE())
         ORDER BY academic_year DESC, position_title'
    );
    $leadershipStmt->execute([$schoolId, $studentId]);
    $leadershipPositions = $leadershipStmt->fetchAll();
    $attendanceStmt = db()->prepare(
        'SELECT attendance_date, status, remarks
         FROM student_attendance
         WHERE school_id = ? AND student_id = ?
         ORDER BY attendance_date DESC LIMIT 20'
    );
    $attendanceStmt->execute([$schoolId, $studentId]);
    $attendance = $attendanceStmt->fetchAll();
    $attendanceSummaryPeriods = school_attendance_summaries(
        $schoolId,
        [$studentId],
        $attendancePeriods,
    )[$studentId] ?? [];
    $attendanceSummary = $attendanceSummaryPeriods['term']
        ?? $attendanceSummaryPeriods['month']
        ?? $attendanceSummary;

    $resultsStmt = db()->prepare(
        'SELECT sa.assessment_name, sa.term_name, sa.total_marks, sa.published_at,
                ss.name AS subject_name, sc.level AS class_level, sc.academic_year,
                ar.score, ar.grade, ar.remarks, ar.created_at
         FROM assessment_results ar
         JOIN school_assessments sa ON sa.id = ar.assessment_id AND sa.school_id = ar.school_id
         JOIN school_subjects ss ON ss.id = sa.subject_id AND ss.school_id = sa.school_id
         JOIN school_classes sc ON sc.id = sa.class_id AND sc.school_id = sa.school_id
         WHERE ar.school_id = ? AND ar.student_id = ? AND sa.published_at IS NOT NULL
         ORDER BY ar.created_at DESC, sa.term_name, ss.name LIMIT 30'
    );
    $resultsStmt->execute([$schoolId, $studentId]);
    $results = $resultsStmt->fetchAll();

    $invoiceStmt = db()->prepare(
        'SELECT id, invoice_number, class_level, academic_year, period_name, amount,
                balance, adjustments_total, status, due_date, fee_snapshot, created_at
         FROM school_invoices WHERE school_id = ? AND student_id = ? AND status <> "draft"
         ORDER BY created_at DESC LIMIT 20'
    );
    $invoiceStmt->execute([$schoolId, $studentId]);
    $invoices = $invoiceStmt->fetchAll();
    $timetableStmt = db()->prepare(
        'SELECT entry.weekday, entry.starts_at, entry.ends_at,
                class.level, class.section, subject.name AS subject_name
         FROM school_timetable_entries entry
         JOIN school_classes class
           ON class.id = entry.class_id AND class.school_id = entry.school_id
         LEFT JOIN school_subjects subject
           ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
         WHERE entry.school_id = ? AND entry.class_id = ? AND entry.published_at IS NOT NULL'
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
         ORDER BY entry.weekday, entry.starts_at'
    );
    $timetableStmt->execute([$schoolId, (int) $student['class_id']]);
    $timetable = $timetableStmt->fetchAll();
    $homeworkStmt = db()->prepare(
        'SELECT assignment.title, assignment.instructions, assignment.learning_material_url,
                assignment.due_at, assignment.published_at, subject.name AS subject_name
         FROM school_assignments assignment
         LEFT JOIN school_subjects subject
           ON subject.id = assignment.subject_id AND subject.school_id = assignment.school_id
         WHERE assignment.school_id = ? AND assignment.class_id = ?
           AND assignment.published_at IS NOT NULL
         ORDER BY assignment.due_at IS NULL, assignment.due_at, assignment.published_at DESC
         LIMIT 30'
    );
    $homeworkStmt->execute([$schoolId, (int) $student['class_id']]);
    $homeworkAssignments = $homeworkStmt->fetchAll();

    $paymentsStmt = db()->prepare(
        'SELECT payment.id, allocation.invoice_id, allocation.amount AS allocated_amount,
                allocation.allocation_type, payment.payment_coverage, payment.payment_method, payment.payment_reference,
                payment.received_at
         FROM school_payment_allocations allocation
         JOIN school_payments payment
           ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
          AND payment.student_id = allocation.student_id
         WHERE allocation.school_id = ? AND allocation.student_id = ?
           AND allocation.status = "active" AND payment.status = "verified"
         ORDER BY payment.received_at DESC, allocation.id DESC LIMIT 50'
    );
    $paymentsStmt->execute([$schoolId, $studentId]);
    $payments = $paymentsStmt->fetchAll();
    $creditStmt = db()->prepare(
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
    $creditStmt->execute([$schoolId, $studentId, $schoolId, $studentId]);
    $availableStudentCredit = (float) $creditStmt->fetchColumn();

    $announcementsStmt = db()->prepare(
        'SELECT title, body, published_at FROM school_announcements
         WHERE school_id = ? AND published_at IS NOT NULL
           AND audience IN ("all", "students")
         ORDER BY published_at DESC LIMIT 10'
    );
    $announcementsStmt->execute([$schoolId]);
    $announcements = $announcementsStmt->fetchAll();

    if (!$studentPreview) {
        $notificationsStmt = db()->prepare(
            'SELECT id, title, body, category, read_at, created_at
             FROM association_notifications WHERE user_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 20'
        );
        $notificationsStmt->execute([(int) $user['id']]);
        $notifications = $notificationsStmt->fetchAll();
    }
}

$schoolName = (string) $school['name'];
$publishedAverage = $results === [] ? null : array_sum(array_map(
    static fn (array $result): float => ((float) $result['score'] / max(1.0, (float) $result['total_marks'])) * 100,
    $results
)) / count($results);
$subjectPerformance = [];
$termPerformance = [];
foreach ($results as $result) {
    $totalMarks = (float) $result['total_marks'];
    if ($totalMarks <= 0) {
        continue;
    }
    $subject = (string) $result['subject_name'];
    $subjectPerformance[$subject]['total'] = ($subjectPerformance[$subject]['total'] ?? 0)
        + ((float) $result['score'] / $totalMarks * 100);
    $subjectPerformance[$subject]['count'] = ($subjectPerformance[$subject]['count'] ?? 0) + 1;
    $termLabel = school_academic_year_label((string) $result['academic_year']) . ' · ' . (string) $result['term_name'];
    $termPerformance[$termLabel]['total'] = ($termPerformance[$termLabel]['total'] ?? 0)
        + ((float) $result['score'] / $totalMarks * 100);
    $termPerformance[$termLabel]['count'] = ($termPerformance[$termLabel]['count'] ?? 0) + 1;
}
$subjectPerformanceChart = [];
foreach ($subjectPerformance as $subject => $performance) {
    $subjectPerformanceChart[$subject] = $performance['total'] / $performance['count'];
}
$termPerformanceChart = [];
foreach (array_reverse($termPerformance, true) as $termLabel => $performance) {
    $termPerformanceChart[$termLabel] = $performance['total'] / $performance['count'];
}
$attendanceChart = [];
if ($attendanceSummaryPeriods !== []) {
    $attendanceChart = [
        'Present' => (int) ($attendanceSummary['present'] ?? 0),
        'Absent' => (int) ($attendanceSummary['absent'] ?? 0),
        'Not recorded' => (int) ($attendanceSummary['unmarked'] ?? 0),
    ];
}
$outstandingInvoiceChart = [];
$invoiceBalances = $invoices;
usort($invoiceBalances, static fn (array $left, array $right): int => (float) $right['balance'] <=> (float) $left['balance']);
foreach (array_slice($invoiceBalances, 0, 8) as $invoice) {
    $label = (string) $invoice['invoice_number'];
    $outstandingInvoiceChart[$label] = (float) $invoice['balance'];
}
render_header('Student dashboard');
?>
<section class="school-identity-banner student-preview-heading">
    <p class="eyebrow">STUDENT PORTAL<?= $studentPreview ? ' · PREVIEW MODE' : '' ?></p>
    <?= school_identity_markup($school) ?>
    <h1>Student dashboard</h1>
    <p><?= $studentPreview
        ? 'Read-only preview of the student experience. All records shown belong to this school; changes to student records, attendance, results, or payments are disabled.'
        : 'Your personal school dashboard. You can view your records here; protected student, academic, and financial records can only be changed by authorized school staff.' ?></p>
    <p class="student-academic-context">
        <strong>School term today:</strong>
        <?= e($attendanceTerm === null ? 'No active term is scheduled' : $attendanceTermLabel . ' · ' . school_academic_year_label((string) $attendanceTerm['academic_year'])) ?>
        <?php if ($student !== null): ?>
            · <strong>Your class:</strong> <?= e($student['class_level'] ? school_class_label(['level' => $student['class_level'], 'section' => $student['class_section']]) : 'Not assigned') ?>
            · <strong>Your academic year:</strong> <?= e($student['academic_year'] ? school_academic_year_label((string) $student['academic_year']) : 'Not assigned') ?>
        <?php endif; ?>
    </p>
</section>
<?php render_school_calendar_preview($schoolId, $user); ?>

<?php if ($studentPreview && count($students) > 1): ?>
<section class="panel spaced-top">
    <form method="get" action="student_dashboard.php" class="student-preview-selector">
        <label for="student_id">Preview active student</label>
        <select id="student_id" name="student_id" onchange="this.form.submit()">
            <?php foreach ($students as $candidate): ?>
                <option value="<?= (int) $candidate['id'] ?>" <?= (int) $candidate['id'] === (int) $student['id'] ? 'selected' : '' ?>>
                    <?= e((string) $candidate['full_name']) ?><?= $candidate['admission_number'] ? ' · ' . e((string) $candidate['admission_number']) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</section>
<?php endif; ?>

<?php if ($student === null): ?>
    <section class="panel spaced-top">
        <?php dashboard_section_heading('students', 'No active student records'); ?>
        <p class="muted">This school has no active students to preview yet. No sample identity or student record has been fabricated.</p>
    </section>
<?php else: ?>
    <section class="student-preview-profile">
        <article class="panel">
            <p class="eyebrow">MY PROFILE</p>
            <?php dashboard_section_heading('profile', (string) $student['full_name']); ?>
            <p>Student ID: <?= e((string) ($student['admission_number'] ?: '—')) ?></p>
            <p>School: <?= e($schoolName) ?></p>
            <p>Class level: <?= e($student['class_level'] ? school_class_label(['level' => $student['class_level'], 'section' => $student['class_section']]) : 'Not assigned') ?></p>
            <p>Academic year: <?= e($student['academic_year'] ? school_academic_year_label((string) $student['academic_year']) : '—') ?></p>
        </article>
        <?php if ($leadershipPositions !== []): ?>
            <article class="panel">
                <?php dashboard_section_heading('staff', 'Student leadership'); ?>
                <ul>
                    <?php foreach ($leadershipPositions as $position): ?>
                        <li><strong><?= e((string) $position['position_title']) ?></strong>
                            · <?= e(school_academic_year_label((string) $position['academic_year'])) ?>
                            · Appointed <?= e((string) $position['starts_on']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endif; ?>
        <article class="panel">
            <p class="eyebrow">ATTENDANCE SNAPSHOT</p>
            <div class="student-preview-stats">
                <div><strong><?= (int) $attendanceSummary['present'] ?></strong><span><?= isset($attendanceSummaryPeriods['term']) ? 'Term Present' : 'Present this month' ?></span></div>
                <div><strong><?= (int) $attendanceSummary['absent'] ?></strong><span><?= isset($attendanceSummaryPeriods['term']) ? 'Term Absent' : 'Absent this month' ?></span></div>
            </div>
            <?php if ($attendanceSummaryPeriods !== []): ?><p class="muted">Blank attendance is Not Recorded, not an absence. Percentages are provisional until all scheduled attendance is recorded.</p><p class="attendance-legend"><span class="attendance-present">✓ Present</span><span class="attendance-absent">✕ Absent</span><span class="attendance-unmarked">· Not recorded</span></p><div class="table-scroll"><table><thead><tr><th>Period</th><th class="attendance-present">✓ Present</th><th class="attendance-absent">✕ Absent</th><th>Not recorded</th><th>Attendance %</th></tr></thead><tbody>
                <?php foreach ($attendanceSummaryPeriods as $periodKey => $summary): ?><tr><th><?= e((string) ($periodKey === 'term' ? $attendanceTermLabel : ($periodKey === 'year' ? 'Academic year ' . ($attendanceTerm['academic_year'] ?? '') : ($periodKey === 'week' ? 'This week' : 'This month')))) ?></th><td><span class="attendance-present"><?= (int) $summary['present'] ?>/<?= (int) $summary['scheduled_days'] ?></span></td><td><span class="attendance-absent"><?= (int) $summary['absent'] ?></span></td><td><?= (int) $summary['unmarked'] ?></td><td><?= $summary['percentage'] === null ? 'Provisional' : e(number_format((float) $summary['percentage'], 2)) . '%' ?></td></tr><?php endforeach; ?>
            </tbody></table></div><details class="dashboard-secondary-details"><summary>View attendance chart</summary><?php render_dashboard_bar_chart('Attendance records · ' . $attendanceTermLabel, $attendanceChart, 'days'); ?></details><?php endif; ?>
        </article>
    </section>

    <section class="dashboard-group spaced-top" aria-labelledby="student-learning-heading">
        <div class="dashboard-group-heading">
            <p class="eyebrow">ACADEMIC MANAGEMENT</p>
            <h2 id="student-learning-heading">Learning and attendance</h2>
        </div>
        <div class="student-preview-grid">
        <article class="panel">
            <?php dashboard_section_heading('book', 'Homework and assignments'); ?>
            <?php if ($homeworkAssignments === []): ?><p class="muted">No homework or assignments have been published for this class.</p>
            <?php else: ?><ul class="student-preview-announcements"><?php foreach ($homeworkAssignments as $assignment): $materialUrl = school_learning_external_http_url($assignment['learning_material_url'] === null ? null : (string) $assignment['learning_material_url']); ?>
                <li>
                    <strong><?= e((string) $assignment['title']) ?></strong>
                    <small><?= e((string) ($assignment['subject_name'] ?: 'Class assignment')) ?> · Due <?= e((string) ($assignment['due_at'] ?: 'no date set')) ?></small>
                    <p><?= nl2br(e((string) $assignment['instructions'])) ?></p>
                    <?php if ($materialUrl !== null): ?><a href="<?= e($materialUrl) ?>" target="_blank" rel="noopener noreferrer">Open learning material</a><?php endif; ?>
                </li>
            <?php endforeach; ?></ul><?php endif; ?>
        </article>
        <article class="panel">
            <?php dashboard_section_heading('timetable', 'My timetable'); ?>
            <?php if ($timetable === []): ?><p class="muted">No published timetable is available for your class.</p>
            <?php else: ?><div class="table-scroll"><table><thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Venue</th></tr></thead><tbody>
                <?php foreach ($timetable as $entry): ?><tr><td><?= e(date('l', strtotime('Sunday +' . ((int) $entry['weekday'] - 1) . ' days'))) ?></td><td><?= e(substr((string) $entry['starts_at'], 0, 5)) ?>–<?= e(substr((string) $entry['ends_at'], 0, 5)) ?></td><td><?= e((string) ($entry['subject_name'] ?: 'Class period')) ?></td><td><?= e(school_class_label(['level' => $entry['level'], 'section' => $entry['section']])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </article>
        <article class="panel">
            <?php dashboard_section_heading('attendance', 'Attendance'); ?>
            <?php if ($attendance === []): ?><p class="muted">No attendance records are available for this student.</p>
            <?php else: ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Status</th><th>Note</th></tr></thead><tbody>
                <?php foreach ($attendance as $record): $indicator = school_attendance_status_indicator((string) $record['status']); ?><tr><td><?= e((string) $record['attendance_date']) ?></td><td><span class="<?= e($indicator['class']) ?>"><strong aria-hidden="true"><?= e($indicator['symbol']) ?></strong> <?= e($indicator['label']) ?></span></td><td><?= e((string) ($record['remarks'] ?: '—')) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </article>
        <article class="panel">
            <?php dashboard_section_heading('results', 'Results and report cards'); ?>
            <?php if (!$studentPreview): ?><p><a class="button" href="report_cards.php">Open my published report card</a></p><?php endif; ?>
            <?php if ($results === []): ?><p class="muted">No results or report records have been published for this student.</p>
            <?php else: ?><p class="muted">Average across published results: <?= e(number_format((float) $publishedAverage, 1)) ?>%</p><div class="table-scroll"><table><thead><tr><th>Assessment</th><th>Term</th><th>Subject</th><th>Score</th><th>Grade</th><th>Published</th></tr></thead><tbody>
                <?php foreach ($results as $result): ?><tr><td><?= e((string) $result['assessment_name']) ?></td><td><?= e((string) $result['term_name']) ?></td><td><?= e((string) $result['subject_name']) ?></td><td><?= e(number_format((float) $result['score'], 2)) ?> / <?= e(number_format((float) $result['total_marks'], 2)) ?></td><td><?= e((string) ($result['grade'] ?: '—')) ?></td><td><?= e((string) $result['published_at']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><details class="dashboard-secondary-details"><summary>View result trends</summary><?php render_dashboard_bar_chart('Average published score by subject · recent records', $subjectPerformanceChart, '%'); ?><?php if (count($termPerformanceChart) > 1): ?><p class="muted">Term comparison uses the latest 30 published assessment results available in this portal.</p><?php render_dashboard_line_chart('Recent published average by academic term', $termPerformanceChart, '%'); ?><?php endif; ?></details><?php endif; ?>
        </article>
        <article class="panel">
            <?php dashboard_section_heading('book', 'English Learning'); ?>
            <p class="muted">Explore grammar lessons, examples, videos, practice questions, and other resources available on the English-learning website.</p>
            <p><a class="button button-primary" href="https://syntextgrammar.netlify.app/" target="_blank" rel="noopener noreferrer">Open English Learning ↗</a></p>
            <small class="muted">Progress and certificates are managed by the external website and are not recorded in this school portal.</small>
        </article>
        </div>
    </section>
    <section class="dashboard-group spaced-top" aria-labelledby="student-account-heading">
        <div class="dashboard-group-heading">
            <p class="eyebrow">SCHOOL ACCOUNT</p>
            <h2 id="student-account-heading">Fees and notices</h2>
        </div>
        <div class="student-preview-grid">
        <article class="panel">
            <?php dashboard_section_heading('finance', 'Fees and payment history'); ?>
            <?php if ($availableStudentCredit > 0): ?><p><strong>Surplus credit for future terms:</strong> GHS <?= e(number_format($availableStudentCredit, 2)) ?></p><?php endif; ?>
            <?php if ($invoices === []): ?><p class="muted">No invoices are available for this student.</p>
            <?php else: ?><details class="dashboard-secondary-details"><summary>View outstanding balance chart</summary><?php render_dashboard_bar_chart('Outstanding balance · highest of latest invoices', $outstandingInvoiceChart, 'GHS'); ?></details><div class="table-scroll"><table><thead><tr><th>Invoice / period</th><th>Amount</th><th>Outstanding</th><th>Status</th><th>Verified payments</th></tr></thead><tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $invoicePayments = array_values(array_filter($payments, static fn (array $payment): bool => (int) $payment['invoice_id'] === (int) $invoice['id'])); ?>
                    <tr><td><?= e((string) $invoice['invoice_number']) ?><br><small><?= e((string) ($invoice['academic_year'] ?: 'Historical')) ?> · <?= e((string) ($invoice['period_name'] ?: '')) ?></small></td><td>GHS <?= e(number_format((float) $invoice['amount'], 2)) ?></td><td>GHS <?= e(number_format((float) $invoice['balance'], 2)) ?></td><td><span class="status-pill <?= e(school_fee_status_class((string) $invoice['status'])) ?>"><?= e(ucfirst((string) $invoice['status'])) ?></span></td><td>
                        <?php if ($invoicePayments === []): ?>No verified allocations<?php else: foreach ($invoicePayments as $payment): ?>GHS <?= e(number_format((float) $payment['allocated_amount'], 2)) ?> allocated (<?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?> · <?= e(str_replace('_', ' ', (string) $payment['allocation_type'])) ?>) · <?= e((string) $payment['received_at']) ?><?php if (!$studentPreview): ?> · <a href="school_payment_receipt.php?id=<?= (int) $payment['id'] ?>">Receipt</a><?php endif; ?><br><?php endforeach; endif; ?>
                    </td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </article>
        <article class="panel">
            <?php dashboard_section_heading('notifications', 'Announcements and notifications'); ?>
            <?php if ($announcements === []): ?><p class="muted">No published student announcements are available.</p>
            <?php else: ?><ul class="student-preview-announcements"><?php foreach ($announcements as $announcement): ?>
                <li><strong><?= e((string) $announcement['title']) ?></strong><small><?= e((string) $announcement['published_at']) ?></small><?php render_dashboard_read_more((string) $announcement['body']); ?></li>
            <?php endforeach; ?></ul><?php endif; ?>
            <?php if ($notifications !== []): ?><h3>Personal notifications</h3><ul class="student-preview-announcements"><?php foreach ($notifications as $notification): ?>
                <li><strong><?= e((string) $notification['title']) ?></strong><small><?= e((string) $notification['created_at']) ?><?= $notification['read_at'] === null ? ' · New' : '' ?></small><?php render_dashboard_read_more((string) $notification['body']); ?></li>
            <?php endforeach; ?></ul><?php else: ?><p class="muted">You have no personal notifications.</p><?php endif; ?>
        </article>
        </div>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
