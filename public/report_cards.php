<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_results.php';

function school_report_grade(float $average): string
{
    return school_grade_for_percent((int) active_school_context()['id'], $average);
}

function school_report_score_label(float $score): string
{
    return rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
}

function school_report_card_format_datetime(?string $timestamp, bool $utc = false): string
{
    global $config;
    if ($timestamp === null || trim($timestamp) === '') {
        return 'Not recorded';
    }
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'Africa/Accra'));
    $sourceTimezone = $utc ? new DateTimeZone('UTC') : $timezone;
    try {
        return (new DateTimeImmutable($timestamp, $sourceTimezone))
            ->setTimezone($timezone)
            ->format('d M Y, H:i');
    } catch (Exception) {
        return 'Not recorded';
    }
}

function school_report_card_local_date(): string
{
    global $config;
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'Africa/Accra'));
    return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
}

function school_report_card_batch_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'ready' => 'Ready',
        'submitted' => 'Submitted for Review',
        'under_review' => 'Under Review',
        'reviewed' => 'Reviewed — Ready to Release',
        'correction_requested' => 'Returned for Correction',
        'published' => 'Released',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function school_report_card_remark_suggestion(array $subjectResults, bool $forHeadmaster = false, string $teacherRemark = ''): ?string
{
    if ($forHeadmaster && trim($teacherRemark) === '') {
        return null;
    }

    $scores = [];
    foreach ($subjectResults as $subjectResult) {
        if (!is_array($subjectResult)) {
            continue;
        }
        $subjectName = trim((string) ($subjectResult['subject_name'] ?? ''));
        $score = $subjectResult['score'] ?? null;
        $totalMarks = $subjectResult['total_marks'] ?? null;
        if ($subjectName === '' || !is_numeric($score) || !is_numeric($totalMarks)
            || (float) $totalMarks <= 0) {
            continue;
        }
        $scores[] = [
            'subject' => $subjectName,
            'percent' => ((float) $score / (float) $totalMarks) * 100,
        ];
    }
    if ($scores === []) {
        return null;
    }

    $average = array_sum(array_column($scores, 'percent')) / count($scores);
    usort($scores, static fn(array $left, array $right): int => $right['percent'] <=> $left['percent']);
    $strongest = $scores[0];
    $needsAttention = $scores[count($scores) - 1];
    $summary = 'The overall subject average is ' . number_format($average, 1) . '%.';
    if (count($scores) > 1 && abs((float) $strongest['percent'] - (float) $needsAttention['percent']) >= 1) {
        $summary .= ' The strongest result is ' . $strongest['subject'] . ' ('
            . number_format((float) $strongest['percent'], 1) . '%); give additional attention to '
            . $needsAttention['subject'] . ' (' . number_format((float) $needsAttention['percent'], 1) . '%).';
    }

    return $forHeadmaster
        ? 'After reviewing the Class Teacher’s remark and the recorded results, ' . $summary . ' Continue building on this academic progress.'
        : 'Your recorded results show ' . $summary . ' Keep working consistently across your subjects.';
}

function school_report_card_notify_class_teachers(
    int $schoolId,
    int $classId,
    string $academicYear,
    int $batchId,
    int $senderUserId,
    string $event,
    string $title,
    string $message
): void {
    $stmt = db()->prepare(
        'SELECT DISTINCT assignment.teacher_user_id
         FROM teacher_class_assignments assignment
         JOIN school_memberships membership
           ON membership.school_id = assignment.school_id
          AND membership.user_id = assignment.teacher_user_id
          AND membership.role_name = "class_teacher" AND membership.status = "active"
         JOIN users recipient ON recipient.id = assignment.teacher_user_id
         WHERE assignment.school_id = ? AND assignment.class_id = ?
           AND assignment.academic_year = ? AND recipient.status = "active"
           AND recipient.email_verified_at IS NOT NULL'
    );
    $stmt->execute([$schoolId, $classId, $academicYear]);
    $notificationKey = 'report-card-batch:' . $batchId . ':' . $event . ':' . bin2hex(random_bytes(6));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
        school_notification_create(
            $schoolId,
            (int) $recipientId,
            $senderUserId,
            $notificationKey,
            'report_card',
            $title,
            $message,
            'report_cards.php?batch_manage_id=' . $batchId,
            $classId
        );
    }
}

function school_report_card_document(array $report, array $school): string
{
    $subjects = is_array($report['subjects'] ?? null) ? $report['subjects'] : [];
    $attendance = is_array($report['attendance'] ?? null) ? $report['attendance'] : [];
    $feeSummary = is_array($report['fee_summary'] ?? null) ? $report['fee_summary'] : [];
    $schoolName = strtoupper(trim((string) ($school['name'] ?? 'School')));
    $schoolId = (int) ($school['id'] ?? 0);
    $logoPath = (string) ($school['logo_path'] ?? '');
    $schoolLogoUrl = $schoolId > 0
        && preg_match('#^school-logos/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $logoPath) === 1
        ? 'school_logo.php?school_id=' . $schoolId . '&amp;variant=report_card'
        : null;
    $position = isset($report['class_position']) ? (int) $report['class_position'] : null;
    $vacationDate = trim((string) ($report['vacation_date'] ?? ''));
    $nextTermDate = trim((string) ($report['next_term_date'] ?? ''));
    $formatDate = static function (string $date): string {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed->format('d M Y') : 'Not configured';
    };

    ob_start();
    ?>
    <article class="terminal-report-card">
        <header class="terminal-report-header">
            <div class="terminal-school-identity-row">
                <div class="terminal-school-logo-slot">
                    <?php if ($schoolLogoUrl !== null): ?><img class="terminal-school-logo" src="<?= e($schoolLogoUrl) ?>" alt=""><?php endif; ?>
                </div>
                <div class="terminal-school-name-address">
                    <strong><?= e($schoolName) ?></strong>
                    <h1 class="terminal-report-title">End of Term Students Report</h1>
                    <div class="terminal-report-year">ACADEMIC YEAR: <?= e((string) ($report['academic_year'] ?? '')) ?></div>
                </div>
            </div>
        </header>

        <section class="report-card-section">
            <div class="terminal-metadata-grid">
                <div><span>Student Name</span><strong><?= e((string) ($report['student_name'] ?? '')) ?></strong></div>
                <div><span>Student ID</span><strong><?= e((string) ($report['admission_number'] ?? '—')) ?></strong></div>
                <div><span>Class</span><strong><?= e((string) ($report['class_label'] ?? '—')) ?></strong></div>
                <div><span>Term</span><strong><?= e((string) ($report['term_name'] ?? '')) ?></strong></div>
                <div><span>Vacation Date</span><strong><?= e($formatDate($vacationDate)) ?></strong></div>
                <div><span>Next Term Begins</span><strong><?= e($formatDate($nextTermDate)) ?></strong></div>
            </div>
        </section>

        <section class="report-card-section">
            <div class="report-card-table-scroll">
            <table class="terminal-mark-table">
                <colgroup>
                    <col class="terminal-mark-subject">
                    <col class="terminal-mark-class-score">
                    <col class="terminal-mark-exam-score">
                    <col class="terminal-mark-total">
                    <col class="terminal-mark-grade">
                    <col class="terminal-mark-position">
                    <col class="terminal-mark-remarks">
                </colgroup>
                <thead><tr><th>SUBJECT</th><th>CLASS (30%)</th><th>EXAMS (70%)</th><th>TOTAL (100%)</th><th>GRADE</th><th>POSITION</th><th>REMARKS</th></tr></thead>
                <tbody>
                    <?php foreach ($subjects as $subject): ?>
                        <?php
                        $classScore = isset($subject['class_score']) ? (float) $subject['class_score'] : null;
                        $examScore = isset($subject['exam_score']) ? (float) $subject['exam_score'] : null;
                        $totalScore = $classScore !== null && $examScore !== null ? $classScore + $examScore : null;
                        ?>
                        <tr>
                            <td><?= e((string) ($subject['subject_name'] ?? '')) ?></td>
                            <td><?= $classScore === null ? '—' : e(school_report_score_label($classScore)) ?></td>
                            <td><?= $examScore === null ? '—' : e(school_report_score_label($examScore)) ?></td>
                            <td><?= $totalScore === null ? '—' : e(school_report_score_label($totalScore)) ?></td>
                            <td><?= e((string) (($subject['grade'] ?? '') ?: '—')) ?></td>
                            <td><?= e(school_rank_position_label(isset($subject['position']) ? (int) $subject['position'] : null)) ?></td>
                            <td><?= e((string) (($subject['remark'] ?? '') ?: '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($subjects === []): ?><tr><td colspan="7">No saved subject results.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>

        <section class="terminal-summary-strip">
            <span><strong>Overall Class Position:</strong> <?= $position === null ? 'Not ranked' : $position ?></span>
            <span><strong>Attendance:</strong>
                <?php if ($attendance !== [] && isset($attendance['present'], $attendance['scheduled_days']) && empty($attendance['incomplete'])): ?>
                    <?= (int) $attendance['present'] ?> days present out of <?= (int) $attendance['scheduled_days'] ?> school days<?= ($attendance['percentage'] ?? null) !== null ? ' · ' . e(number_format((float) $attendance['percentage'], 2)) . '%' : '' ?>
                <?php elseif ($attendance !== [] && isset($attendance['present'], $attendance['scheduled_days'])): ?>
                    Provisional: <?= (int) $attendance['present'] ?> present; <?= (int) ($attendance['recorded_days'] ?? 0) ?> of <?= (int) $attendance['scheduled_days'] ?> school days recorded
                <?php else: ?>Not recorded<?php endif; ?>
            </span>
        </section>

        <section class="terminal-summary-strip report-card-section">
            <span><strong>Fee status at generation:</strong> <?= e(ucwords(str_replace('_', ' ', (string) ($feeSummary['status'] ?? 'snapshot unavailable')))) ?></span>
            <?php if (isset($feeSummary['invoice_number'])): ?><span><strong>Invoice:</strong> <?= e((string) $feeSummary['invoice_number']) ?></span><?php endif; ?>
            <?php if (isset($feeSummary['invoiced_amount'])): ?><span><strong>Invoiced:</strong> GHS <?= e(number_format((float) $feeSummary['invoiced_amount'], 2)) ?></span><?php endif; ?>
            <?php if (isset($feeSummary['verified_payments'])): ?><span><strong>Verified payments:</strong> GHS <?= e(number_format((float) $feeSummary['verified_payments'], 2)) ?></span><?php endif; ?>
            <?php if (isset($feeSummary['approved_adjustments'])): ?><span><strong>Approved adjustments:</strong> GHS <?= e(number_format((float) $feeSummary['approved_adjustments'], 2)) ?></span><?php endif; ?>
            <?php if (isset($feeSummary['balance'])): ?><span><strong>Balance:</strong> GHS <?= e(number_format((float) $feeSummary['balance'], 2)) ?></span><?php endif; ?>
            <?php if ($feeSummary === []): ?><span>This historical report was generated before fee snapshots were available.</span><?php elseif (($feeSummary['status'] ?? '') === 'pending_financial_review'): ?><span>Invoice and payment totals require financial review.</span><?php elseif (($feeSummary['status'] ?? '') === 'not_assessed'): ?><span>No fee invoice was recorded for this student and term when the report was generated.</span><?php endif; ?>
        </section>

        <section class="terminal-behaviour-grid report-card-section">
            <div><strong>Conduct</strong><span><?= e((string) (($report['conduct'] ?? '') ?: '—')) ?></span></div>
            <div><strong>Interest</strong><span><?= e((string) (($report['interest'] ?? '') ?: '—')) ?></span></div>
            <div><strong>Attitude</strong><span><?= e((string) (($report['attitude'] ?? '') ?: '—')) ?></span></div>
        </section>

        <section class="terminal-comments-grid report-card-section">
            <div><h2>CLASS TEACHER’S REMARKS</h2><p><?= nl2br(e((string) (($report['teacher_comment'] ?? '') ?: '—'))) ?></p></div>
            <div><h2>HEAD TEACHER’S REMARKS</h2><p><?= nl2br(e((string) (($report['headmaster_remarks'] ?? '') ?: '—'))) ?></p></div>
        </section>

        <section class="terminal-signatures report-card-section">
            <div><span>CLASS TEACHER’S SIGNATURE</span><strong><?= e((string) (($report['class_teacher_signature_name'] ?? '') ?: '')) ?></strong><div class="signature-box"></div></div>
            <div><span>HEAD TEACHER’S SIGNATURE</span><strong><?= e((string) (($report['headmaster_signature_name'] ?? '') ?: '')) ?></strong><div class="signature-box"></div></div>
            <div><span>SCHOOL STAMP</span><strong>&nbsp;</strong><div class="signature-box"></div></div>
        </section>
    </article>
    <?php
    return (string) ob_get_clean();
}

function school_report_card_next_term_date(int $schoolId, string $academicYear, string $termName): string
{
    $termStmt = db()->prepare(
        'SELECT term.ends_on FROM school_terms term
         JOIN school_academic_years year ON year.id = term.academic_year_id AND year.school_id = term.school_id
         WHERE term.school_id = ? AND year.academic_year = ? AND term.term_name = ?
         ORDER BY term.starts_on DESC LIMIT 1'
    );
    $termStmt->execute([$schoolId, $academicYear, $termName]);
    $termEnd = $termStmt->fetchColumn();
    if (!$termEnd) {
        return 'Not configured';
    }
    $nextTermStmt = db()->prepare(
        'SELECT starts_on FROM school_terms WHERE school_id = ? AND starts_on > ?
         ORDER BY starts_on ASC LIMIT 1'
    );
    $nextTermStmt->execute([$schoolId, (string) $termEnd]);
    $nextTermStart = $nextTermStmt->fetchColumn();
    return $nextTermStart === false ? '' : (string) $nextTermStart;
}

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$gradeRulesStmt = db()->prepare(
    'SELECT grade, minimum_percent, remark FROM school_grade_rules WHERE school_id = ? ORDER BY minimum_percent DESC, grade_order'
);
$gradeRulesStmt->execute([$schoolId]);
$gradeRules = $gradeRulesStmt->fetchAll();
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$studentPortal = $schoolRole === 'school_student';
$parentPortal = $schoolRole === 'school_parent';
if ($studentPortal) {
    require_school_permission($schoolId, 'student.portal.view');
} elseif ($parentPortal) {
    require_school_permission($schoolId, 'report_cards.view_own');
} else {
    require_school_permission($schoolId, 'report_cards.view');
}
$canViewFormerStudents = !$studentPortal && school_has_permission($user, $schoolId, 'students.lifecycle.manage');
$classTeacher = $schoolRole === 'class_teacher';
$headmasterRole = $schoolRole === 'school_headmaster';
$proprietorRole = $schoolRole === 'school_proprietor';
$reportCardAdmin = $headmasterRole || $proprietorRole;
$canPrepareReportCards = $classTeacher || $proprietorRole;
$reportCardMode = (string) ($_GET['mode'] ?? '');
if (!in_array($reportCardMode, ['generate', 'review', 'publish', 'view'], true)) {
    $reportCardMode = '';
}
$teacherId = (int) $user['id'];
$reportSettingsStmt = db()->prepare(
    'SELECT setting_key, setting_value FROM school_settings
     WHERE school_id = ? AND setting_key = "report_card_promotion_cutoff"'
);
$reportSettingsStmt->execute([$schoolId]);
$reportSettings = [];
foreach ($reportSettingsStmt->fetchAll() as $setting) {
    $reportSettings[(string) $setting['setting_key']] = (string) $setting['setting_value'];
}
$reportPromotionCutoff = filter_var($reportSettings['report_card_promotion_cutoff'] ?? '25', FILTER_VALIDATE_INT);
if ($reportPromotionCutoff === false || $reportPromotionCutoff < 1 || $reportPromotionCutoff > 500) {
    $reportPromotionCutoff = 25;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'prepare_report_cards') {
        if (!$canPrepareReportCards) {
            http_response_code(403);
            exit('Only the assigned Class Teacher or authorized Proprietor may prepare report cards.');
        }
        $requestedClassId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $requestedTerm = trim((string) ($_POST['term_name'] ?? ''));
        $requestedAssessment = trim((string) ($_POST['assessment_name'] ?? ''));
        if ($requestedClassId === false || $requestedClassId < 1 || $requestedTerm === '' || $requestedAssessment === '') {
            flash('error', 'Select a class, term, and examination to prepare report cards.');
            redirect('report_cards.php');
        }
        $classStmt = db()->prepare(
            'SELECT id, academic_year FROM school_classes
             WHERE id = ? AND school_id = ? LIMIT 1'
        );
        $classStmt->execute([$requestedClassId, $schoolId]);
        $reportClass = $classStmt->fetch();
        if (!$reportClass) {
            http_response_code(403);
            exit('The selected class is not available in this school.');
        }
        if ($classTeacher) {
            $classAssignment = db()->prepare(
                'SELECT 1 FROM teacher_class_assignments
                 WHERE school_id = ? AND class_id = ? AND teacher_user_id = ? AND academic_year = ? LIMIT 1'
            );
            $classAssignment->execute([$schoolId, $requestedClassId, $teacherId, (string) $reportClass['academic_year']]);
            if (!$classAssignment->fetchColumn()) {
                http_response_code(403);
                exit('You can only prepare report cards for your assigned classes.');
            }
        } elseif (!$reportCardAdmin) {
            http_response_code(403);
            exit('Your role cannot prepare report cards.');
        }
        $requestedStudentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($requestedStudentId !== false && $requestedStudentId > 0) {
            $selectedStudentCheck = db()->prepare(
                'SELECT id FROM school_students
                 WHERE id = ? AND school_id = ? AND class_id = ? AND school_status = "active" LIMIT 1'
            );
            $selectedStudentCheck->execute([$requestedStudentId, $schoolId, $requestedClassId]);
            if (!$selectedStudentCheck->fetchColumn()) {
                http_response_code(403);
                exit('The selected student is not enrolled in this report class.');
            }
        } else {
            $requestedStudentId = false;
        }
        $existingReadyBatchStmt = db()->prepare(
            'SELECT id FROM school_report_card_batches
             WHERE school_id = ? AND class_id = ? AND academic_year = ?
               AND term_name = ? AND assessment_name = ?
               AND status IN ("draft", "ready", "submitted", "under_review", "reviewed", "correction_requested")
             ORDER BY revision_number DESC, id DESC
             LIMIT 1'
        );
        $existingReadyBatchStmt->execute([
            $schoolId,
            $requestedClassId,
            (string) $reportClass['academic_year'],
            $requestedTerm,
            $requestedAssessment,
        ]);
        $existingReadyBatchId = $existingReadyBatchStmt->fetchColumn();
        if ($existingReadyBatchId !== false) {
            $editTarget = 'report_cards.php?batch_manage_id=' . (int) $existingReadyBatchId;
            if ($requestedStudentId !== false) {
                $editTarget .= '&edit_student_id=' . (int) $requestedStudentId;
            }
            redirect($editTarget . '#edit-report-details');
        }
        $resultsGroup = school_assessment_group(
            $schoolId,
            $requestedClassId,
            (string) $reportClass['academic_year'],
            $requestedTerm,
            $requestedAssessment
        );
        if (!$resultsGroup['ready_for_report_cards']) {
            flash('error', 'Report cards are unavailable until every required subject is complete and reviewed.');
            redirect('assessments.php?results_class_id=' . $requestedClassId
                . '&results_term=' . rawurlencode($requestedTerm)
                . '&results_assessment=' . rawurlencode($requestedAssessment));
        }
        $termStmt = db()->prepare(
            'SELECT t.id, t.school_id, y.academic_year, t.term_name, t.starts_on, t.ends_on
             FROM school_terms t
             JOIN school_academic_years y ON y.id = t.academic_year_id AND y.school_id = t.school_id
             WHERE t.school_id = ? AND y.academic_year = ? AND t.term_name = ?
             ORDER BY t.starts_on DESC LIMIT 1'
        );
        $termStmt->execute([$schoolId, (string) $reportClass['academic_year'], $requestedTerm]);
        $reportTerm = $termStmt->fetch() ?: null;
        $attendanceByStudent = $reportTerm
            ? school_attendance_summaries(
                $schoolId,
                array_column($resultsGroup['students'], 'id'),
                school_attendance_date_periods($reportTerm, null, $schoolId)
            )
            : [];
        $generatedVacationDate = school_report_card_local_date();
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $classLock = $pdo->prepare(
                'SELECT id FROM school_classes
                 WHERE id = ? AND school_id = ? AND academic_year = ? FOR UPDATE'
            );
            $classLock->execute([$requestedClassId, $schoolId, (string) $reportClass['academic_year']]);
            if (!$classLock->fetchColumn()) {
                throw new RuntimeException('The selected class changed while report cards were being prepared.');
            }
            $lockedReadyBatchStmt = $pdo->prepare(
                'SELECT id FROM school_report_card_batches
                 WHERE school_id = ? AND class_id = ? AND academic_year = ?
                   AND term_name = ? AND assessment_name = ?
                   AND status IN ("draft", "ready", "submitted", "under_review", "reviewed", "correction_requested")
                 ORDER BY revision_number DESC, id DESC LIMIT 1'
            );
            $lockedReadyBatchStmt->execute([
                $schoolId,
                $requestedClassId,
                (string) $reportClass['academic_year'],
                $requestedTerm,
                $requestedAssessment,
            ]);
            $lockedReadyBatchId = $lockedReadyBatchStmt->fetchColumn();
            if ($lockedReadyBatchId !== false) {
                $pdo->commit();
                $editTarget = 'report_cards.php?batch_manage_id=' . (int) $lockedReadyBatchId;
                if ($requestedStudentId !== false) {
                    $editTarget .= '&edit_student_id=' . (int) $requestedStudentId;
                }
                redirect($editTarget . '#edit-report-details');
            }
            $revisionStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(revision_number), 0) + 1
                 FROM school_report_card_batches
                 WHERE school_id = ? AND class_id = ? AND academic_year = ?
                   AND term_name = ? AND assessment_name = ?'
            );
            $revisionStmt->execute([
                $schoolId,
                $requestedClassId,
                (string) $reportClass['academic_year'],
                $requestedTerm,
                $requestedAssessment,
            ]);
            $revisionNumber = (int) $revisionStmt->fetchColumn();
            $batchStmt = $pdo->prepare(
                'INSERT INTO school_report_card_batches
                 (school_id, class_id, academic_year, term_name, assessment_name, status,
                  revision_number, generated_by)
                 VALUES (?, ?, ?, ?, ?, "draft", ?, ?)'
            );
            $batchStmt->execute([
                $schoolId,
                $requestedClassId,
                (string) $reportClass['academic_year'],
                $requestedTerm,
                $requestedAssessment,
                $revisionNumber,
                $teacherId,
            ]);
            $batchId = (int) $pdo->lastInsertId();
            $entryStmt = $pdo->prepare(
                'INSERT INTO school_report_card_entries
                 (school_id, batch_id, student_id, student_name, admission_number,
                  total_score, average_percent, overall_grade, class_position,
                  subject_results, attendance_summary, grading_scale, fee_summary, vacation_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $feeSnapshotStmt = $pdo->prepare(
                'SELECT invoice.invoice_number, invoice.amount, invoice.balance, invoice.adjustments_total,
                        COALESCE((
                            SELECT SUM(allocation.amount)
                            FROM school_payment_allocations allocation
                            JOIN school_payments payment
                              ON payment.id = allocation.payment_id
                             AND payment.school_id = allocation.school_id
                             AND payment.student_id = allocation.student_id
                            WHERE allocation.school_id = invoice.school_id
                              AND allocation.invoice_id = invoice.id
                              AND allocation.student_id = invoice.student_id
                              AND allocation.status = "active"
                              AND payment.status = "verified"
                        ), 0) AS verified_payments,
                        COALESCE((
                            SELECT SUM(adjustment.amount)
                            FROM school_invoice_adjustments adjustment
                            WHERE adjustment.school_id = invoice.school_id
                              AND adjustment.invoice_id = invoice.id
                              AND adjustment.status = "approved"
                              AND adjustment.adjustment_type <> "payment_arrangement"
                        ), 0) AS approved_adjustments
                 FROM school_invoices invoice
                 WHERE invoice.school_id = ? AND invoice.student_id = ?
                   AND invoice.academic_year = ? AND invoice.period_name = ?
                 LIMIT 1'
            );
            foreach ($resultsGroup['students'] as $resultStudent) {
                if ($resultStudent['average'] === null) {
                    throw new RuntimeException('A student result is not ready for report-card generation.');
                }
                $subjectResults = [];
                foreach ($resultsGroup['subjects'] as $subject) {
                    $score = $resultStudent['subject_scores'][(int) $subject['subject_id']] ?? null;
                    if ($score === null) {
                        throw new RuntimeException('A required subject score is missing from a report-card snapshot.');
                    }
                    $subjectRemark = '';
                    foreach ($gradeRules as $gradeRule) {
                        if ((string) $gradeRule['grade'] === (string) $score['grade']) {
                            $subjectRemark = (string) ($gradeRule['remark'] ?? '');
                            break;
                        }
                    }
                    $subjectResults[] = [
                        'subject_id' => (int) $subject['subject_id'],
                        'subject_name' => (string) $subject['subject_name'],
                        'code' => $subject['code'],
                        'score' => (float) $score['score'],
                        'class_score' => $score['class_score'],
                        'exam_score' => $score['exam_score'],
                        'class_score_maximum' => (float) $score['class_score_maximum'],
                        'exam_score_maximum' => (float) $score['exam_score_maximum'],
                        'total_marks' => (float) $score['total_marks'],
                        'grade' => (string) $score['grade'],
                        'remark' => $subjectRemark,
                        'position' => $score['position'] ?? null,
                    ];
                }
                $feeSnapshotStmt->execute([
                    $schoolId,
                    (int) $resultStudent['id'],
                    (string) $reportClass['academic_year'],
                    $requestedTerm,
                ]);
                $feeInvoice = $feeSnapshotStmt->fetch() ?: null;
                $feeSummary = ['status' => 'not_assessed'];
                if ($feeInvoice !== null) {
                    $invoiceAmount = (float) $feeInvoice['amount'];
                    $verifiedPayments = (float) $feeInvoice['verified_payments'];
                    $approvedAdjustments = (float) $feeInvoice['approved_adjustments'];
                    $invoiceBalance = (float) $feeInvoice['balance'];
                    $recordedAdjustments = (float) $feeInvoice['adjustments_total'];
                    $consistent = abs(round($invoiceAmount - $verifiedPayments - $approvedAdjustments - $invoiceBalance, 2)) < 0.01
                        && abs(round($recordedAdjustments - $approvedAdjustments, 2)) < 0.01;
                    $feeStatus = 'pending_financial_review';
                    if ($consistent) {
                        $feeStatus = $invoiceBalance <= 0.00001
                            ? 'paid'
                            : ($verifiedPayments > 0 ? 'partially_paid' : 'outstanding');
                    }
                    $feeSummary = [
                        'status' => $feeStatus,
                        'invoice_number' => (string) $feeInvoice['invoice_number'],
                        'invoiced_amount' => $invoiceAmount,
                        'verified_payments' => $verifiedPayments,
                        'approved_adjustments' => $approvedAdjustments,
                        'balance' => $invoiceBalance,
                    ];
                }
                $entryStmt->execute([
                    $schoolId,
                    $batchId,
                    (int) $resultStudent['id'],
                    (string) $resultStudent['full_name'],
                    $resultStudent['admission_number'],
                    number_format((float) $resultStudent['total'], 2, '.', ''),
                    number_format((float) $resultStudent['average'], 2, '.', ''),
                    (string) $resultStudent['grade'],
                    $resultStudent['position'] === null ? null : (int) $resultStudent['position'],
                    json_encode($subjectResults, JSON_THROW_ON_ERROR),
                    json_encode($attendanceByStudent[(int) $resultStudent['id']] ?? [], JSON_THROW_ON_ERROR),
                    json_encode($gradeRules, JSON_THROW_ON_ERROR),
                    json_encode($feeSummary, JSON_THROW_ON_ERROR),
                    $generatedVacationDate,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException && !($exception instanceof PDOException)) {
                flash('error', $exception->getMessage());
            } else {
                error_log('Report card preparation failed: ' . $exception->getMessage());
                flash('error', 'Report cards could not be prepared. No partial batch was saved.');
            }
            redirect('report_cards.php');
        }
        audit($teacherId, 'school_report_cards_prepared', 'school_id=' . $schoolId . ';batch_id=' . $batchId);
        flash('success', 'Report-card drafts are ready for assessment ratings and remarks.');
        $editTarget = 'report_cards.php?batch_manage_id=' . $batchId;
        if ($requestedStudentId !== false) {
            $editTarget .= '&edit_student_id=' . (int) $requestedStudentId;
        }
        redirect($editTarget . '#edit-report-details');
    }

    if ($action === 'save_report_card_notes') {
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $editStudentId = filter_var($_POST['edit_student_id'] ?? null, FILTER_VALIDATE_INT);
        $navigateStudentId = filter_var($_POST['navigate_to_student_id'] ?? null, FILTER_VALIDATE_INT);
        $notes = $_POST['notes'] ?? [];
        if ($batchId === false || $batchId < 1 || !is_array($notes)) {
            flash('error', 'Choose a valid report-card batch and notes.');
            redirect('report_cards.php');
        }
        $editTarget = 'report_cards.php?batch_manage_id=' . $batchId;
        if ($editStudentId !== false && $editStudentId > 0) {
            $editTarget .= '&edit_student_id=' . (int) $editStudentId;
        }
        $batchStmt = db()->prepare(
            'SELECT id, class_id, academic_year, status, is_outdated FROM school_report_card_batches
             WHERE id = ? AND school_id = ? LIMIT 1'
        );
        $batchStmt->execute([$batchId, $schoolId]);
        $managedBatch = $batchStmt->fetch();
        if (!$managedBatch || $managedBatch['status'] === 'published' || (bool) $managedBatch['is_outdated']) {
            flash('error', 'This report-card batch cannot be changed.');
            redirect('report_cards.php');
        }
        if ($classTeacher) {
            $assignmentStmt = db()->prepare(
                'SELECT 1 FROM teacher_class_assignments
                 WHERE school_id = ? AND class_id = ? AND teacher_user_id = ? AND academic_year = ? LIMIT 1'
            );
            $assignmentStmt->execute([$schoolId, $managedBatch['class_id'], $teacherId, $managedBatch['academic_year']]);
            if (!$assignmentStmt->fetchColumn()) {
                http_response_code(403);
                exit('You can only edit report cards for your assigned classes.');
            }
        } elseif (!$reportCardAdmin) {
            http_response_code(403);
            exit('Your role cannot edit report-card remarks.');
        }
        $editableStatuses = $classTeacher
            ? ['draft', 'ready', 'correction_requested']
            : ($headmasterRole ? ['submitted', 'under_review'] : ['draft', 'ready', 'correction_requested']);
        if (!in_array((string) $managedBatch['status'], $editableStatuses, true)) {
            http_response_code(403);
            exit('This report-card batch is not open for your role to edit.');
        }
        $entryCheck = db()->prepare(
            'SELECT entry.student_id, entry.vacation_date,
                    note.teacher_comment, note.headmaster_remarks,
                    note.student_behaviour, note.conduct, note.interest, note.attitude, note.next_term_date,
                    note.class_teacher_signature_name, note.headmaster_signature_name,
                    note.proprietor_signature_name,
                    note.promotion_override, note.promotion_override_reason
             FROM school_report_card_entries entry
             LEFT JOIN school_report_card_notes note ON note.school_id = entry.school_id
               AND note.batch_id = entry.batch_id AND note.student_id = entry.student_id
             WHERE entry.school_id = ? AND entry.batch_id = ? AND entry.student_id = ?'
        );
        $validatedNotes = [];
        $validatedVacationDates = [];
        $promotionOverrides = [];
        foreach ($notes as $studentKey => $note) {
            $noteStudentId = filter_var($studentKey, FILTER_VALIDATE_INT);
            if ($noteStudentId === false || $noteStudentId < 1 || !is_array($note)) {
                continue;
            }
            $entryCheck->execute([$schoolId, $batchId, $noteStudentId]);
            $existingEntry = $entryCheck->fetch();
            if (!$existingEntry) {
                http_response_code(403);
                exit('A report-card entry is outside this batch.');
            }
            $teacherComment = null;
            $studentBehaviour = null;
            $conduct = null;
            $interest = null;
            $attitude = null;
            $nextTermDate = null;
            $classTeacherSignature = null;
            $vacationDate = null;
            if ($classTeacher) {
                $teacherValue = $note['teacher_comment'] ?? '';
                $conductValue = $note['conduct'] ?? ($note['student_behaviour'] ?? '');
                $interestValue = $note['interest'] ?? '';
                $attitudeValue = $note['attitude'] ?? '';
                $dateValue = $note['next_term_date'] ?? '';
                $signatureValue = $note['class_teacher_signature_name'] ?? '';
                if (!is_string($teacherValue) || !is_string($conductValue)
                    || !is_string($interestValue) || !is_string($attitudeValue)
                    || !is_string($dateValue) || !is_string($signatureValue)) {
                    flash('error', 'Class-teacher report fields must be submitted as text.');
                    redirect($editTarget . '#edit-report-details');
                }
                $teacherComment = trim($teacherValue);
                $conduct = trim($conductValue);
                $interest = trim($interestValue);
                $attitude = trim($attitudeValue);
                $studentBehaviour = $conduct;
                $nextTermDate = trim($dateValue);
                $classTeacherSignature = trim($signatureValue);
                if (strlen($teacherComment) > 1000 || strlen($conduct) > 160
                    || strlen($interest) > 160 || strlen($attitude) > 160
                    || strlen($classTeacherSignature) > 160) {
                    flash('error', 'Class-teacher remarks, behaviour fields, or signature exceed the allowed length.');
                    redirect($editTarget . '#edit-report-details');
                }
                if ($nextTermDate !== '') {
                    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $nextTermDate);
                    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $nextTermDate) {
                        flash('error', 'Enter the next term date using the date picker.');
                        redirect($editTarget . '#edit-report-details');
                    }
                }
            }
            $headmasterRemarks = null;
            $headmasterSignature = null;
            if ($headmasterRole) {
                $headmasterValue = $note['headmaster_remarks'] ?? '';
                $headmasterSignatureValue = $note['headmaster_signature_name'] ?? '';
                $vacationDateValue = $note['vacation_date'] ?? ($existingEntry['vacation_date'] ?? '');
                if (!is_string($headmasterValue) || !is_string($headmasterSignatureValue)
                    || !is_string($vacationDateValue)) {
                    flash('error', 'Headmaster report fields must be submitted as text.');
                    redirect($editTarget . '#edit-report-details');
                }
                $headmasterRemarks = trim($headmasterValue);
                $headmasterSignature = trim($headmasterSignatureValue);
                $vacationDate = trim($vacationDateValue);
                if (strlen($headmasterRemarks) > 1000 || strlen($headmasterSignature) > 160) {
                    flash('error', 'Headmaster remarks or signature exceed the allowed length.');
                    redirect($editTarget . '#edit-report-details');
                }
                $parsedVacationDate = DateTimeImmutable::createFromFormat('!Y-m-d', $vacationDate);
                if (!$parsedVacationDate || $parsedVacationDate->format('Y-m-d') !== $vacationDate) {
                    flash('error', 'Enter the date of vacation using the date picker.');
                    redirect($editTarget . '#edit-report-details');
                }
            }
            $proprietorSignature = null;
            if ($proprietorRole) {
                $proprietorSignatureValue = $note['proprietor_signature_name'] ?? '';
                if (!is_string($proprietorSignatureValue)) {
                    flash('error', 'Proprietor signature name must be submitted as text.');
                    $editTarget = 'report_cards.php?batch_manage_id=' . $batchId;
                    if ($editStudentId !== false && $editStudentId > 0) {
                        $editTarget .= '&edit_student_id=' . (int) $editStudentId;
                    }
                    redirect($editTarget . '#edit-report-details');
                }
                $proprietorSignature = trim($proprietorSignatureValue);
                if (strlen($proprietorSignature) > 160) {
                    flash('error', 'Proprietor signature name cannot exceed 160 characters.');
                    redirect($editTarget . '#edit-report-details');
                }
            }
            $promotionOverride = null;
            $promotionReason = null;
            if ($reportCardAdmin) {
                $overrideInput = $note['promotion_override'] ?? '';
                $reasonInput = $note['promotion_override_reason'] ?? '';
                if (!is_string($overrideInput) || !is_string($reasonInput)) {
                    flash('error', 'Promotion override values must be submitted as text.');
                    redirect($editTarget . '#edit-report-details');
                }
                $promotionOverride = trim($overrideInput);
                $promotionReason = trim($reasonInput);
                if ($promotionOverride !== '' && !in_array($promotionOverride, ['promoted', 'pending'], true)) {
                    flash('error', 'Choose a valid promotion status override.');
                    redirect($editTarget . '#edit-report-details');
                }
                if ($promotionOverride !== '' && strlen($promotionReason) < 5) {
                    flash('error', 'Enter at least five characters explaining the promotion override.');
                    redirect($editTarget . '#edit-report-details');
                }
                if (strlen($promotionReason) > 500) {
                    flash('error', 'Promotion override reasons cannot exceed 500 characters.');
                    redirect($editTarget . '#edit-report-details');
                }
                if ($promotionOverride === '') {
                    $promotionReason = '';
                }
                $newOverride = $promotionOverride === '' ? null : $promotionOverride;
                $newReason = $newOverride === null ? null : $promotionReason;
                if (($existingEntry['promotion_override'] ?? null) !== $newOverride
                    || ($existingEntry['promotion_override_reason'] ?? null) !== $newReason) {
                    $promotionOverrides[] = [
                        'student_id' => $noteStudentId,
                        'status' => $newOverride ?? 'default',
                    ];
                }
            }
            $validatedNotes[] = [
                $noteStudentId,
                $teacherComment === null || $teacherComment === '' ? null : $teacherComment,
                $headmasterRemarks === null || $headmasterRemarks === '' ? null : $headmasterRemarks,
                $studentBehaviour === null || $studentBehaviour === '' ? null : $studentBehaviour,
                $conduct === null || $conduct === '' ? null : $conduct,
                $interest === null || $interest === '' ? null : $interest,
                $attitude === null || $attitude === '' ? null : $attitude,
                $nextTermDate === null || $nextTermDate === '' ? null : $nextTermDate,
                $classTeacherSignature === null || $classTeacherSignature === '' ? null : $classTeacherSignature,
                $headmasterSignature === null || $headmasterSignature === '' ? null : $headmasterSignature,
                $proprietorSignature === null || $proprietorSignature === '' ? null : $proprietorSignature,
                $promotionOverride === null || $promotionOverride === '' ? null : $promotionOverride,
                $promotionReason === null || $promotionReason === '' ? null : $promotionReason,
            ];
            if ($headmasterRole) {
                $validatedVacationDates[] = [$noteStudentId, $vacationDate];
            }
        }
        if ($editStudentId !== false && $editStudentId > 0) {
            $focusedEntryIncluded = false;
            foreach ($validatedNotes as $validatedNote) {
                if ((int) $validatedNote[0] === $editStudentId) {
                    $focusedEntryIncluded = true;
                    break;
                }
            }
            if (!$focusedEntryIncluded) {
                http_response_code(403);
                exit('The selected report entry is not included in these saved changes.');
            }
        }
        if ($navigateStudentId !== false && $navigateStudentId > 0) {
            $navigationEntryCheck = db()->prepare(
                'SELECT 1 FROM school_report_card_entries
                 WHERE school_id = ? AND batch_id = ? AND student_id = ? LIMIT 1'
            );
            $navigationEntryCheck->execute([$schoolId, $batchId, $navigateStudentId]);
            if (!$navigationEntryCheck->fetchColumn()) {
                http_response_code(403);
                exit('The requested next report is outside this batch.');
            }
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $lockBatchStmt = $pdo->prepare(
                'SELECT status FROM school_report_card_batches
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $lockBatchStmt->execute([$batchId, $schoolId]);
            $lockedBatchStatus = $lockBatchStmt->fetchColumn();
            if (!is_string($lockedBatchStatus) || !in_array($lockedBatchStatus, $editableStatuses, true)) {
                throw new RuntimeException('This report-card batch is no longer open for your role to edit.');
            }
            $saveNote = $pdo->prepare(
                'INSERT INTO school_report_card_notes
                     (school_id, batch_id, student_id, teacher_comment, headmaster_remarks,
                      student_behaviour, conduct, interest, attitude, next_term_date, class_teacher_signature_name,
                      headmaster_signature_name, proprietor_signature_name,
                      promotion_override, promotion_override_reason, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                        teacher_comment = IF(?, VALUES(teacher_comment), teacher_comment),
                        headmaster_remarks = IF(?, VALUES(headmaster_remarks), headmaster_remarks),
                        student_behaviour = IF(?, VALUES(student_behaviour), student_behaviour),
                        conduct = IF(?, VALUES(conduct), conduct),
                        interest = IF(?, VALUES(interest), interest),
                        attitude = IF(?, VALUES(attitude), attitude),
                        next_term_date = IF(?, VALUES(next_term_date), next_term_date),
                        class_teacher_signature_name = IF(?, VALUES(class_teacher_signature_name), class_teacher_signature_name),
                        headmaster_signature_name = IF(?, VALUES(headmaster_signature_name), headmaster_signature_name),
                        proprietor_signature_name = IF(?, VALUES(proprietor_signature_name), proprietor_signature_name),
                        promotion_override = IF(?, VALUES(promotion_override), promotion_override),
                        promotion_override_reason = IF(?, VALUES(promotion_override_reason), promotion_override_reason),
                        updated_by = VALUES(updated_by),
                        updated_at = UTC_TIMESTAMP()'
            );
            foreach ($validatedNotes as $validatedNote) {
                $saveNote->execute(array_merge(
                    [$schoolId, $batchId],
                    $validatedNote,
                    [
                        $teacherId,
                        $classTeacher ? 1 : 0,
                        $headmasterRole ? 1 : 0,
                        $classTeacher ? 1 : 0,
                        $classTeacher ? 1 : 0,
                        $classTeacher ? 1 : 0,
                        $classTeacher ? 1 : 0,
                        $classTeacher ? 1 : 0,
                        $classTeacher ? 1 : 0,
                        $headmasterRole ? 1 : 0,
                        $proprietorRole ? 1 : 0,
                        $reportCardAdmin ? 1 : 0,
                        $reportCardAdmin ? 1 : 0,
                    ]
                ));
            }
            if ($headmasterRole) {
                $saveVacationDate = $pdo->prepare(
                    'UPDATE school_report_card_entries
                     SET vacation_date = ?
                     WHERE school_id = ? AND batch_id = ? AND student_id = ?'
                );
                foreach ($validatedVacationDates as [$vacationStudentId, $vacationDate]) {
                    $saveVacationDate->execute([$vacationDate, $schoolId, $batchId, $vacationStudentId]);
                }
            }
            if ($headmasterRole && (string) $managedBatch['status'] === 'submitted') {
                $markUnderReview = $pdo->prepare(
                    'UPDATE school_report_card_batches
                     SET status = "under_review"
                     WHERE id = ? AND school_id = ? AND status = "submitted"'
                );
                $markUnderReview->execute([$batchId, $schoolId]);
                if ($markUnderReview->rowCount() !== 1) {
                    throw new RuntimeException('The report-card batch changed while review progress was being saved.');
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Report-card notes save failed: ' . $exception->getMessage());
            flash('error', 'Report-card comments could not be saved. No partial changes were applied.');
            redirect($editTarget . '#edit-report-details');
        }
        audit($teacherId, 'school_report_card_notes_updated', 'school_id=' . $schoolId . ';batch_id=' . $batchId);
        foreach ($promotionOverrides as $override) {
            audit(
                $teacherId,
                'school_report_card_promotion_overridden',
                'school_id=' . $schoolId . ';batch_id=' . $batchId . ';student_id='
                    . $override['student_id'] . ';status=' . $override['status']
            );
        }
        flash('success', 'Report-card details saved.');
        if ($navigateStudentId !== false && $navigateStudentId > 0) {
            $successTarget = 'report_cards.php?batch_manage_id=' . $batchId
                . '&edit_student_id=' . (int) $navigateStudentId;
        } else {
            $successTarget = $editTarget;
        }
        redirect($successTarget . '#edit-report-details');
    }

    if ($action === 'submit_report_cards') {
        if (!$classTeacher) {
            http_response_code(403);
            exit('Only the assigned Class Teacher may submit report cards for review.');
        }
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1) {
            flash('error', 'Choose a valid report-card batch.');
            redirect('report_cards.php');
        }
        $batchStmt = db()->prepare(
            'SELECT id, class_id, academic_year, status, is_outdated
             FROM school_report_card_batches
             WHERE id = ? AND school_id = ? LIMIT 1'
        );
        $batchStmt->execute([$batchId, $schoolId]);
        $batch = $batchStmt->fetch();
        if (!$batch || !in_array((string) $batch['status'], ['draft', 'ready', 'correction_requested'], true)
            || (bool) $batch['is_outdated']) {
            flash('error', 'Only a current draft or returned report-card batch can be submitted.');
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        $assignmentStmt = db()->prepare(
            'SELECT 1 FROM teacher_class_assignments
             WHERE school_id = ? AND class_id = ? AND teacher_user_id = ? AND academic_year = ? LIMIT 1'
        );
        $assignmentStmt->execute([$schoolId, (int) $batch['class_id'], $teacherId, (string) $batch['academic_year']]);
        if (!$assignmentStmt->fetchColumn()) {
            http_response_code(403);
            exit('You can only submit report cards for your assigned classes.');
        }
        $completionStmt = db()->prepare(
            'SELECT entry.student_name, note.teacher_comment, note.class_teacher_signature_name
             FROM school_report_card_entries entry
             LEFT JOIN school_report_card_notes note
               ON note.school_id = entry.school_id AND note.batch_id = entry.batch_id
              AND note.student_id = entry.student_id
             WHERE entry.school_id = ? AND entry.batch_id = ?
             ORDER BY entry.student_name, entry.student_id'
        );
        $completionStmt->execute([$schoolId, $batchId]);
        $completionEntries = $completionStmt->fetchAll();
        $incompleteTeacherReports = array_values(array_map(
            static fn(array $entry): string => (string) $entry['student_name'],
            array_filter(
                $completionEntries,
                static fn(array $entry): bool => trim((string) ($entry['teacher_comment'] ?? '')) === ''
                    || trim((string) ($entry['class_teacher_signature_name'] ?? '')) === ''
            )
        ));
        if ($completionEntries === [] || $incompleteTeacherReports !== []) {
            $message = 'Add a Class Teacher remark and signature to every report before submitting the batch.';
            if ($incompleteTeacherReports !== []) {
                $message .= ' Reports needing attention: '
                    . implode(', ', array_slice($incompleteTeacherReports, 0, 10))
                    . (count($incompleteTeacherReports) > 10 ? ', and others.' : '.');
            }
            flash('error', $message);
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        $submitStmt = db()->prepare(
            'UPDATE school_report_card_batches
             SET status = "submitted", submitted_by = ?, submitted_at = UTC_TIMESTAMP(),
                 reviewed_by = NULL, reviewed_at = NULL, correction_requested_by = NULL,
                 correction_requested_at = NULL, correction_reason = NULL
             WHERE id = ? AND school_id = ? AND status IN ("draft", "ready", "correction_requested")
               AND is_outdated = 0'
        );
        $submitStmt->execute([$teacherId, $batchId, $schoolId]);
        if ($submitStmt->rowCount() !== 1) {
            flash('error', 'The report-card batch could not be submitted; reload it and try again.');
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        audit($teacherId, 'school_report_cards_submitted', 'school_id=' . $schoolId . ';batch_id=' . $batchId);
        school_notify_roles(
            $schoolId,
            ['school_headmaster'],
            $teacherId,
            'report-cards-submitted:' . $batchId,
            'report_card',
            'Report cards submitted for review',
            'A Class Teacher submitted report cards for Headmaster review.',
            'report_cards.php?batch_manage_id=' . $batchId
        );
        flash('success', 'Report cards submitted to the Headmaster for review.');
        redirect('report_cards.php?batch_manage_id=' . $batchId);
    }

    if ($action === 'complete_review_return') {
        if (!$headmasterRole) {
            http_response_code(403);
            exit('Only the Headmaster may complete a report-card review.');
        }
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1) {
            flash('error', 'Choose a valid report-card batch.');
            redirect('report_cards.php');
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $reviewBatchStmt = $pdo->prepare(
                'SELECT id, class_id, academic_year FROM school_report_card_batches
                 WHERE id = ? AND school_id = ? AND status IN ("submitted", "under_review")
                   AND is_outdated = 0 LIMIT 1 FOR UPDATE'
            );
            $reviewBatchStmt->execute([$batchId, $schoolId]);
            $reviewBatch = $reviewBatchStmt->fetch();
            if (!$reviewBatch) {
                throw new RuntimeException('Only a current batch submitted for review can be returned as reviewed.');
            }
            $reviewEntriesStmt = $pdo->prepare(
                'SELECT entry.student_name, entry.vacation_date,
                        note.headmaster_remarks, note.headmaster_signature_name
                 FROM school_report_card_entries entry
                 LEFT JOIN school_report_card_notes note
                   ON note.school_id = entry.school_id AND note.batch_id = entry.batch_id
                  AND note.student_id = entry.student_id
                 WHERE entry.school_id = ? AND entry.batch_id = ?
                 ORDER BY entry.student_name, entry.student_id'
            );
            $reviewEntriesStmt->execute([$schoolId, $batchId]);
            $reviewEntries = $reviewEntriesStmt->fetchAll();
            $missingReviewNames = [];
            foreach ($reviewEntries as $reviewEntry) {
                if (trim((string) ($reviewEntry['headmaster_remarks'] ?? '')) === ''
                    || trim((string) ($reviewEntry['headmaster_signature_name'] ?? '')) === ''
                    || trim((string) ($reviewEntry['vacation_date'] ?? '')) === '') {
                    $missingReviewNames[] = (string) $reviewEntry['student_name'];
                }
            }
            if ($reviewEntries === [] || $missingReviewNames !== []) {
                $missingList = array_slice($missingReviewNames, 0, 10);
                $message = 'Complete the Headmaster remark, signature, and vacation date for every report before returning the batch.';
                if ($missingList !== []) {
                    $message .= ' Reports needing attention: ' . implode(', ', $missingList)
                        . (count($missingReviewNames) > count($missingList) ? ', and others.' : '.');
                }
                throw new RuntimeException($message);
            }
            $completeReviewStmt = $pdo->prepare(
                'UPDATE school_report_card_batches
                 SET status = "reviewed", reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND school_id = ? AND status IN ("submitted", "under_review")
                   AND is_outdated = 0'
            );
            $completeReviewStmt->execute([$teacherId, $batchId, $schoolId]);
            if ($completeReviewStmt->rowCount() !== 1) {
                throw new RuntimeException('The batch changed while the review was being completed. Reload it and try again.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
            } else {
                error_log('Report-card review completion failed: ' . $exception->getMessage());
                flash('error', 'The report-card review could not be completed. No partial status change was saved.');
            }
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        audit($teacherId, 'school_report_cards_reviewed_returned', 'school_id=' . $schoolId . ';batch_id=' . $batchId);
        school_report_card_notify_class_teachers(
            $schoolId,
            (int) $reviewBatch['class_id'],
            (string) $reviewBatch['academic_year'],
            $batchId,
            $teacherId,
            'reviewed-returned',
            'Reviewed reports ready to release',
            'The Headmaster completed the review and returned the report-card batch. Review the remarks, then release the reports.'
        );
        flash('success', count($reviewEntries) . ' reviewed reports were returned to the Class Teacher.');
        redirect('report_cards.php?batch_manage_id=' . $batchId);
    }

    if ($action === 'return_report_cards') {
        if (!$headmasterRole) {
            http_response_code(403);
            exit('Only the Headmaster may return report cards for correction.');
        }
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($batchId === false || $batchId < 1 || $reason === '' || strlen($reason) > 500) {
            flash('error', 'Choose a batch and provide a correction reason of up to 500 characters.');
            redirect('report_cards.php');
        }
        $returnBatchStmt = db()->prepare(
            'SELECT class_id, academic_year FROM school_report_card_batches
             WHERE id = ? AND school_id = ? AND status IN ("submitted", "under_review")
               AND is_outdated = 0 LIMIT 1'
        );
        $returnBatchStmt->execute([$batchId, $schoolId]);
        $returnBatch = $returnBatchStmt->fetch();
        if (!$returnBatch) {
            flash('error', 'Only a current batch under review can be returned for correction.');
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        $returnStmt = db()->prepare(
            'UPDATE school_report_card_batches
             SET status = "correction_requested", correction_requested_by = ?,
                 correction_requested_at = UTC_TIMESTAMP(), correction_reason = ?,
                 reviewed_by = NULL, reviewed_at = NULL
             WHERE id = ? AND school_id = ? AND status IN ("submitted", "under_review")
               AND is_outdated = 0'
        );
        $returnStmt->execute([$teacherId, $reason, $batchId, $schoolId]);
        if ($returnStmt->rowCount() !== 1) {
            flash('error', 'Only a current submitted or reviewed batch can be returned for correction.');
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        audit($teacherId, 'school_report_cards_returned', 'school_id=' . $schoolId . ';batch_id=' . $batchId . ';reason=' . $reason);
        school_report_card_notify_class_teachers(
            $schoolId,
            (int) $returnBatch['class_id'],
            (string) $returnBatch['academic_year'],
            $batchId,
            $teacherId,
            'correction-requested',
            'Report-card batch returned for correction',
            'The Headmaster returned the report-card batch for correction: ' . $reason
        );
        flash('success', 'Report cards returned to the Class Teacher for correction.');
        redirect('report_cards.php?batch_manage_id=' . $batchId);
    }

    if ($action === 'publish_report_cards') {
        if (!$classTeacher) {
            http_response_code(403);
            exit('Only the assigned Class Teacher may release reviewed report cards.');
        }
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1) {
            flash('error', 'Choose a valid report-card batch.');
            redirect('report_cards.php');
        }
        $releaseBatchStmt = db()->prepare(
            'SELECT class_id, academic_year FROM school_report_card_batches
             WHERE id = ? AND school_id = ? AND status = "reviewed" AND is_outdated = 0
             LIMIT 1'
        );
        $releaseBatchStmt->execute([$batchId, $schoolId]);
        $releaseBatch = $releaseBatchStmt->fetch();
        if (!$releaseBatch) {
            flash('error', 'Only a current batch returned by the Headmaster can be released.');
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        $releaseAssignmentStmt = db()->prepare(
            'SELECT 1 FROM teacher_class_assignments
             WHERE school_id = ? AND class_id = ? AND teacher_user_id = ? AND academic_year = ?
             LIMIT 1'
        );
        $releaseAssignmentStmt->execute([
            $schoolId,
            (int) $releaseBatch['class_id'],
            $teacherId,
            (string) $releaseBatch['academic_year'],
        ]);
        if (!$releaseAssignmentStmt->fetchColumn()) {
            http_response_code(403);
            exit('You can only release report cards for your assigned classes.');
        }
        $headmasterCompletionStmt = db()->prepare(
            'SELECT COUNT(*) AS total_entries,
                    SUM(CASE WHEN note.headmaster_remarks IS NULL OR TRIM(note.headmaster_remarks) = ""
                        OR note.headmaster_signature_name IS NULL OR TRIM(note.headmaster_signature_name) = ""
                        THEN 1 ELSE 0 END) AS incomplete_entries
             FROM school_report_card_entries entry
             LEFT JOIN school_report_card_notes note
               ON note.school_id = entry.school_id AND note.batch_id = entry.batch_id
              AND note.student_id = entry.student_id
             WHERE entry.school_id = ? AND entry.batch_id = ?'
        );
        $headmasterCompletionStmt->execute([$schoolId, $batchId]);
        $headmasterCompletion = $headmasterCompletionStmt->fetch() ?: [];
        if ((int) ($headmasterCompletion['total_entries'] ?? 0) < 1
            || (int) ($headmasterCompletion['incomplete_entries'] ?? 0) > 0) {
            flash('error', 'Every report must have a Headmaster remark and signature before the Class Teacher can release the batch.');
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        $publishStmt = db()->prepare(
            'UPDATE school_report_card_batches SET status = "published", published_by = ?, published_at = UTC_TIMESTAMP()
             WHERE id = ? AND school_id = ? AND status = "reviewed"
               AND is_outdated = 0
               AND (SELECT COUNT(*) FROM school_report_card_entries entry
                    WHERE entry.batch_id = school_report_card_batches.id
                      AND entry.school_id = school_report_card_batches.school_id) > 0'
        );
        $publishStmt->execute([$teacherId, $batchId, $schoolId]);
        if ($publishStmt->rowCount() !== 1) {
            flash('error', 'Only a complete, current reviewed batch can be released.');
            redirect('report_cards.php?batch_manage_id=' . $batchId);
        }
        $recipientStmt = db()->prepare(
            'SELECT link.user_id, entry.student_id FROM school_report_card_entries entry
             JOIN school_student_account_links link ON link.school_id = entry.school_id
               AND link.student_id = entry.student_id AND link.status = "active"
             JOIN school_memberships membership ON membership.school_id = link.school_id
               AND membership.user_id = link.user_id AND membership.status = "active"
             WHERE entry.school_id = ? AND entry.batch_id = ?
             UNION
             SELECT parent_membership.user_id, entry.student_id
             FROM school_report_card_entries entry
             JOIN school_parent_links parent_link ON parent_link.school_id = entry.school_id
               AND parent_link.student_id = entry.student_id AND parent_link.status = "verified"
             JOIN school_guardians guardian ON guardian.id = parent_link.guardian_id
               AND guardian.school_id = parent_link.school_id
             JOIN users parent_account ON parent_account.id = parent_link.user_id
               AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
               AND parent_account.email_verified_at IS NOT NULL AND parent_account.status = "active"
             JOIN school_memberships parent_membership ON parent_membership.user_id = parent_account.id
               AND parent_membership.school_id = entry.school_id AND parent_membership.role_name = "school_parent"
               AND parent_membership.status = "active"
             WHERE entry.school_id = ? AND entry.batch_id = ?'
        );
        $recipientStmt->execute([$schoolId, $batchId, $schoolId, $batchId]);
        foreach ($recipientStmt->fetchAll() as $recipient) {
            school_notification_create(
                $schoolId,
                (int) $recipient['user_id'],
                $teacherId,
                'report-card-published:' . $batchId . ':' . (int) $recipient['student_id'],
                'report_card',
                'Report card released',
                'Your report card has been released and is ready to view.',
                'report_cards.php?student_id=' . (int) $recipient['student_id'],
                null,
                null,
                null
            );
        }
        audit($teacherId, 'school_report_cards_published', 'school_id=' . $schoolId . ';batch_id=' . $batchId);
        flash('success', 'Reviewed report cards have been released to linked student accounts.');
        redirect('report_cards.php?batch_manage_id=' . $batchId);
    }

    flash('error', 'Unknown report-card action.');
    redirect('report_cards.php');
}

$studentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);
$classId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
$termId = filter_var($_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
$requestedAcademicYear = trim((string) ($_GET['academic_year'] ?? ''));
$assessmentName = trim((string) ($_GET['assessment_name'] ?? ''));
$pageAcademicPeriod = school_page_academic_period($schoolId);
$requestedTermName = trim((string) ($_GET['term_name'] ?? ''));
$usePagePeriod = $requestedTermName === '' && ($termId === false || $termId < 1) && $pageAcademicPeriod !== null;
$termName = $requestedTermName !== '' ? $requestedTermName : ($usePagePeriod ? $pageAcademicPeriod['term_name'] : '');
$termStmt = db()->prepare(
             'SELECT t.id, t.school_id, y.academic_year, t.term_name, t.starts_on, t.ends_on
     FROM school_terms t
     JOIN school_academic_years y ON y.id = t.academic_year_id AND y.school_id = t.school_id
     WHERE t.school_id = ? ORDER BY t.starts_on DESC'
);
$termStmt->execute([$schoolId]);
$terms = $termStmt->fetchAll();
$selectedTerm = null;
if ($termId !== false && $termId > 0) {
    foreach ($terms as $term) {
        if ((int) $term['id'] === $termId) {
            $selectedTerm = $term;
            break;
        }
    }
    if ($selectedTerm === null) {
        http_response_code(404);
        exit('The selected academic term is not available in this school.');
    }
    $termName = (string) $selectedTerm['term_name'];
}
if ($selectedTerm === null && $termName !== '' && !$usePagePeriod) {
    foreach ($terms as $term) {
        if ((string) $term['term_name'] === $termName) {
            $selectedTerm = $term;
            break;
        }
    }
}
if ($selectedTerm === null && $usePagePeriod) {
    foreach ($terms as $term) {
        if ((string) $term['academic_year'] === $pageAcademicPeriod['academic_year']
            && (string) $term['term_name'] === $termName) {
            $selectedTerm = $term;
            break;
        }
    }
}
$reportAcademicYear = $selectedTerm !== null
    ? (string) $selectedTerm['academic_year']
    : ($requestedAcademicYear !== ''
        ? $requestedAcademicYear
        : ($usePagePeriod ? (string) $pageAcademicPeriod['academic_year'] : null));
if ($selectedTerm !== null && $requestedAcademicYear !== ''
    && $requestedAcademicYear !== (string) $selectedTerm['academic_year']) {
    http_response_code(400);
    exit('The selected term does not belong to the selected academic year.');
}
if ($studentPortal) {
    $linkedStudentStmt = db()->prepare(
        'SELECT ss.id FROM school_student_account_links link
         JOIN school_memberships membership ON membership.user_id = link.user_id
           AND membership.school_id = link.school_id AND membership.status = "active"
         JOIN school_students ss ON ss.id = link.student_id AND ss.school_id = link.school_id
         WHERE link.school_id = ? AND link.user_id = ? AND link.status = "active"
           AND ss.school_status = "active" LIMIT 1'
    );
    $linkedStudentStmt->execute([$schoolId, (int) $user['id']]);
    $linkedStudentId = $linkedStudentStmt->fetchColumn();
    if ($linkedStudentId === false) {
        http_response_code(403);
        exit('Your account is not linked to an active student record.');
    }
    $studentId = (int) $linkedStudentId;
}

$classStmt = db()->prepare(
    'SELECT id, level, section, academic_year FROM school_classes WHERE school_id = ?'
        . ($classTeacher ? ' AND EXISTS (
            SELECT 1 FROM teacher_class_assignments tca
            WHERE tca.school_id = school_classes.school_id AND tca.class_id = school_classes.id
              AND tca.teacher_user_id = ? AND tca.academic_year = school_classes.academic_year
        )' : '') . ' ORDER BY academic_year DESC, level, name'
);
$classStmt->execute($classTeacher ? [$schoolId, $teacherId] : [$schoolId]);
$classes = $classStmt->fetchAll();
$selectedClass = null;
if ($classId !== false && $classId > 0) {
    foreach ($classes as $class) {
        if ((int) $class['id'] === $classId) {
            $selectedClass = $class;
            break;
        }
    }
    if ($selectedClass === null) {
        http_response_code(403);
        exit('The selected class is not available to your account in this school.');
    }
    if ($reportAcademicYear !== null && $reportAcademicYear !== (string) $selectedClass['academic_year']) {
        http_response_code(400);
        exit('The selected class does not belong to the selected academic year.');
    }
    $reportAcademicYear = (string) $selectedClass['academic_year'];
}
$reportAcademicYears = array_values(array_unique(array_map(
    static fn (array $term): string => (string) $term['academic_year'],
    $terms
)));
$selectedAcademicYear = $reportAcademicYear ?? '';

$studentSql =
    'SELECT ss.id, ss.full_name, ss.school_status, ss.class_id,
            sc.level AS class_level, sc.section AS class_section, sc.academic_year
     FROM school_students ss
     LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
     WHERE ss.school_id = ?' . ($studentPortal ? ' AND ss.id = ?' : ($canViewFormerStudents ? '' : ' AND ss.school_status = "active"'))
        . ($parentPortal ? ' AND EXISTS (
            SELECT 1 FROM school_parent_links parent_link
            JOIN school_guardians guardian ON guardian.id = parent_link.guardian_id
              AND guardian.school_id = parent_link.school_id
            JOIN users parent_account ON parent_account.id = parent_link.user_id
              AND parent_account.id = ? AND parent_account.status = "active"
              AND parent_account.email_verified_at IS NOT NULL
              AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
            JOIN school_memberships parent_membership ON parent_membership.user_id = parent_account.id
              AND parent_membership.school_id = parent_link.school_id AND parent_membership.status = "active"
              AND parent_membership.role_name = "school_parent"
            WHERE parent_link.school_id = ss.school_id AND parent_link.student_id = ss.id
              AND parent_link.status = "verified"
        )' : '')
        . ($classTeacher ? ' AND EXISTS (
            SELECT 1 FROM teacher_class_assignments tca
            WHERE tca.school_id = sc.school_id AND tca.class_id = sc.id
              AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
        )' : '')
        . ' ORDER BY sc.academic_year DESC, ss.full_name ASC';
$studentStmt = db()->prepare($studentSql);
$studentParams = [$schoolId];
if ($studentPortal) {
    $studentParams[] = $studentId;
}
if ($parentPortal) {
    $studentParams[] = $teacherId;
}
if ($classTeacher) {
    $studentParams[] = $teacherId;
}
$studentStmt->execute($studentParams);
$students = $studentStmt->fetchAll();
if ($studentPortal && ($classId === false || $classId < 1) && isset($students[0]['class_id'])) {
    $classId = (int) $students[0]['class_id'];
}

$selectedStudent = null;
$reportRows = [];
$average = 0.0;
$attendanceSummaries = [];
$attendanceTermLabel = 'Current term';
$publishedBatches = [];
$selectedReportCard = null;
$selectedReportCardDocument = null;
$generatedReportCard = false;
$reportGenerationError = '';
$reportAssessmentOptions = [];
$reportHasIncompleteResults = false;

if ($studentId !== false && $studentId > 0) {
    $studentCheck = db()->prepare(
        'SELECT ss.id, ss.full_name, ss.admission_number, ss.school_status, ss.class_id,
                sc.academic_year, sc.level AS class_level, sc.section AS class_section,
                guardian.phone AS guardian_phone
         FROM school_students ss
         LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         LEFT JOIN school_guardians guardian ON guardian.id = ss.guardian_id AND guardian.school_id = ss.school_id
         WHERE ss.id = ? AND ss.school_id = ?' . ($studentPortal ? ' AND ss.school_status = "active"' : ($canViewFormerStudents ? '' : ' AND ss.school_status = "active"'))
            . ($parentPortal ? ' AND EXISTS (
                SELECT 1 FROM school_parent_links parent_link
                JOIN school_guardians guardian ON guardian.id = parent_link.guardian_id
                  AND guardian.school_id = parent_link.school_id
                JOIN users parent_account ON parent_account.id = parent_link.user_id
                  AND parent_account.id = ? AND parent_account.status = "active"
                  AND parent_account.email_verified_at IS NOT NULL
                  AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
                JOIN school_memberships parent_membership ON parent_membership.user_id = parent_account.id
                  AND parent_membership.school_id = parent_link.school_id AND parent_membership.status = "active"
                  AND parent_membership.role_name = "school_parent"
                WHERE parent_link.school_id = ss.school_id AND parent_link.student_id = ss.id
                  AND parent_link.status = "verified"
            )' : '')
            . ($classTeacher ? ' AND EXISTS (
                SELECT 1 FROM teacher_class_assignments tca
                WHERE tca.school_id = sc.school_id AND tca.class_id = sc.id
                  AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
            )' : '')
            . ($classId !== false && $classId > 0 ? ' AND ss.class_id = ?' : '')
            . ($reportAcademicYear !== null ? ' AND sc.academic_year = ?' : '') . '
         LIMIT 1'
    );
    $studentCheckParams = [$studentId, $schoolId];
    if ($parentPortal) {
        $studentCheckParams[] = $teacherId;
    }
    if ($classTeacher) {
        $studentCheckParams[] = $teacherId;
    }
    if ($classId !== false && $classId > 0) {
        $studentCheckParams[] = $classId;
    }
    if ($reportAcademicYear !== null) {
        $studentCheckParams[] = $reportAcademicYear;
    }
    $studentCheck->execute($studentCheckParams);
    $selectedStudent = $studentCheck->fetch();

    if ($selectedStudent) {
        if ($classId !== false && $classId > 0 && $selectedTerm !== null) {
            $reportAssessmentStmt = db()->prepare(
                'SELECT DISTINCT assessment.assessment_name
                 FROM school_assessments assessment
                 WHERE assessment.school_id = ? AND assessment.class_id = ?
                   AND assessment.academic_year = ? AND assessment.term_name = ?
                   AND assessment.workflow_status IN ("reviewed", "published")
                   AND EXISTS (
                       SELECT 1 FROM assessment_results result
                       WHERE result.school_id = assessment.school_id
                         AND result.assessment_id = assessment.id
                         AND result.student_id = ?
                   )
                 ORDER BY assessment.assessment_name'
            );
            $reportAssessmentStmt->execute([
                $schoolId,
                $classId,
                (string) $selectedTerm['academic_year'],
                (string) $selectedTerm['term_name'],
                (int) $selectedStudent['id'],
            ]);
            $reportAssessmentOptions = array_values(array_map(
                static fn(array $row): string => (string) $row['assessment_name'],
                $reportAssessmentStmt->fetchAll()
            ));
            if ($assessmentName === '' && $reportAssessmentOptions !== []) {
                if (count($reportAssessmentOptions) === 1) {
                    $assessmentName = $reportAssessmentOptions[0];
                } else {
                    foreach ($reportAssessmentOptions as $reportAssessmentOption) {
                        if (strcasecmp($reportAssessmentOption, 'End of Term Examination') === 0) {
                            $assessmentName = $reportAssessmentOption;
                            break;
                        }
                    }
                }
            }
        }

        $resultStmt = db()->prepare(
            'SELECT sa.assessment_name, sa.term_name, s.name AS subject_name, s.code, ar.score, sa.total_marks, ar.grade
             FROM assessment_results ar
             JOIN school_assessments sa ON sa.id = ar.assessment_id AND sa.school_id = ar.school_id
             JOIN school_subjects s ON s.id = sa.subject_id AND s.school_id = sa.school_id
             JOIN school_classes result_class ON result_class.id = sa.class_id AND result_class.school_id = sa.school_id
             WHERE ar.school_id = ? AND ar.student_id = ?' . (($studentPortal || $parentPortal) ? ' AND sa.published_at IS NOT NULL' : '') . ($termName !== '' ? ' AND sa.term_name = ?' : '') . ($assessmentName !== '' ? ' AND sa.assessment_name = ?' : '') . '
             ' . ($reportAcademicYear !== null ? ' AND result_class.academic_year = ?' : '') . '
             ORDER BY sa.term_name, s.name, sa.assessment_name'
        );
        $params = [$schoolId, $studentId];
        if ($termName !== '') {
            $params[] = $termName;
        }
        if ($assessmentName !== '') {
            $params[] = $assessmentName;
        }
        if ($reportAcademicYear !== null) {
            $params[] = $reportAcademicYear;
        }
        $resultStmt->execute($params);
        $reportRows = $resultStmt->fetchAll();

        $total = 0.0;
        $count = 0;
        foreach ($reportRows as $row) {
            $total += $studentPortal
                ? ((float) $row['score'] / max(1.0, (float) $row['total_marks'])) * 100
                : (float) $row['score'];
            $count += 1;
        }
        if ($count > 0) {
            $average = $total / $count;
        }
        $attendanceTerm = $usePagePeriod
            ? $selectedTerm
            : ($selectedTerm ?? school_current_attendance_term($schoolId));
        $attendanceTermLabel = (string) ($attendanceTerm['term_name'] ?? 'Current term');
        $attendanceSummaries = school_attendance_summaries(
            $schoolId,
            [(int) $studentId],
            school_attendance_date_periods($attendanceTerm, null, $schoolId),
        )[(int) $studentId] ?? [];

        $batchSql =
            'SELECT b.id, b.class_id, b.academic_year, b.term_name, b.assessment_name,
                        b.created_at, b.submitted_at, b.reviewed_at, b.published_at, b.status, b.revision_number, b.is_outdated,
                    c.level AS class_level, c.section AS class_section
             FROM school_report_card_batches b
                         JOIN school_classes c ON c.id = b.class_id AND c.school_id = b.school_id
                             AND c.academic_year = b.academic_year
             WHERE b.school_id = ? AND b.status IN '
                . ($studentPortal || $parentPortal || (!$classTeacher && !$reportCardAdmin)
                    ? '("published")'
                    : '("draft", "ready", "submitted", "under_review", "reviewed", "correction_requested", "published")') . '
               AND EXISTS (
                   SELECT 1 FROM school_report_card_entries entry
                   WHERE entry.school_id = b.school_id AND entry.batch_id = b.id
                     AND entry.student_id = ?
               )';
        $batchParams = [$schoolId, (int) $studentId];
        if ($termName !== '') {
            $batchSql .= ' AND b.term_name = ?';
            $batchParams[] = $termName;
        }
        if ($assessmentName !== '') {
            $batchSql .= ' AND b.assessment_name = ?';
            $batchParams[] = $assessmentName;
        }
        if ($reportAcademicYear !== null) {
            $batchSql .= ' AND b.academic_year = ?';
            $batchParams[] = $reportAcademicYear;
        }
        if ($classId !== false && $classId > 0) {
            $batchSql .= ' AND b.class_id = ?';
            $batchParams[] = $classId;
        }
        $batchSql .= ' ORDER BY CASE WHEN b.status = "published" THEN 0 ELSE 1 END,
                       b.published_at DESC, b.id DESC';
        $batchStmt = db()->prepare($batchSql);
        $batchStmt->execute($batchParams);
        $publishedBatches = $batchStmt->fetchAll();
        $requestedBatchId = filter_var($_GET['batch_id'] ?? null, FILTER_VALIDATE_INT);
        foreach ($publishedBatches as $batch) {
            if ($requestedBatchId !== false && (int) $batch['id'] === $requestedBatchId) {
                $selectedReportCard = $batch;
                break;
            }
        }
        if ($selectedReportCard === null && !$studentPortal && $requestedBatchId !== false && $requestedBatchId > 0) {
            $previewStmt = db()->prepare(
                'SELECT batch.id, batch.class_id, batch.academic_year, batch.term_name,
                        batch.assessment_name, batch.created_at, batch.submitted_at, batch.reviewed_at, batch.published_at, batch.status,
                        batch.revision_number, batch.is_outdated,
                        class.level AS class_level, class.section AS class_section
                 FROM school_report_card_batches batch
                 JOIN school_classes class ON class.id = batch.class_id AND class.school_id = batch.school_id
                 JOIN school_report_card_entries entry ON entry.batch_id = batch.id
                   AND entry.school_id = batch.school_id AND entry.student_id = ?
                 WHERE batch.id = ? AND batch.school_id = ?
                   AND batch.status IN ("draft", "ready", "submitted", "under_review", "reviewed", "correction_requested", "published")
                 LIMIT 1'
            );
            $previewStmt->execute([(int) $studentId, $requestedBatchId, $schoolId]);
            $previewBatch = $previewStmt->fetch() ?: null;
            if ($previewBatch && $classTeacher) {
                $previewPermission = db()->prepare(
                    'SELECT 1 FROM teacher_class_assignments
                     WHERE school_id = ? AND class_id = ? AND teacher_user_id = ? AND academic_year = ? LIMIT 1'
                );
                $previewPermission->execute([$schoolId, $previewBatch['class_id'], $teacherId, $previewBatch['academic_year']]);
                if (!$previewPermission->fetchColumn()) {
                    $previewBatch = null;
                }
            } elseif ($previewBatch && !$reportCardAdmin) {
                $previewBatch = null;
            }
            $selectedReportCard = $previewBatch;
        }
        if ($selectedReportCard === null && $publishedBatches !== []) {
            $selectedReportCard = $publishedBatches[0];
        }
        if ($selectedReportCard !== null) {
            $entryStmt = db()->prepare(
                'SELECT student_name, admission_number, total_score, average_percent, overall_grade, class_position,
                        vacation_date,
                    subject_results, attendance_summary, grading_scale, fee_summary
                 FROM school_report_card_entries
                 WHERE school_id = ? AND batch_id = ? AND student_id = ? LIMIT 1'
            );
            $entryStmt->execute([$schoolId, (int) $selectedReportCard['id'], (int) $studentId]);
            $entry = $entryStmt->fetch();
            if ($entry) {
                $selectedReportCard['student_name'] = (string) $entry['student_name'];
                $selectedReportCard['admission_number'] = $entry['admission_number'] === null
                    ? null
                    : (string) $entry['admission_number'];
                $selectedReportCard['results'] = json_decode((string) $entry['subject_results'], true, 512, JSON_THROW_ON_ERROR);
                $selectedReportCard['ranked_student'] = [
                    'total' => (float) $entry['total_score'],
                    'average' => (float) $entry['average_percent'],
                    'grade' => (string) $entry['overall_grade'],
                    'position' => $entry['class_position'] === null ? null : (int) $entry['class_position'],
                ];
                $selectedReportCard['grading_scale'] = $entry['grading_scale'] === null
                    ? $gradeRules
                    : json_decode((string) $entry['grading_scale'], true, 512, JSON_THROW_ON_ERROR);
                $selectedReportCard['attendance'] = $entry['attendance_summary'] === null
                    ? []
                    : json_decode((string) $entry['attendance_summary'], true, 512, JSON_THROW_ON_ERROR);
                $reportTermStmt = db()->prepare(
                    'SELECT id, school_id, academic_year, term_name, starts_on, ends_on
                     FROM school_terms
                     WHERE school_id = ? AND academic_year = ? AND term_name = ?
                     ORDER BY starts_on DESC LIMIT 1'
                );
                $reportTermStmt->execute([
                    $schoolId,
                    (string) $selectedReportCard['academic_year'],
                    (string) $selectedReportCard['term_name'],
                ]);
                $liveAttendanceTerm = $reportTermStmt->fetch() ?: null;
                if ($liveAttendanceTerm !== null) {
                    $selectedReportCard['attendance'] = school_attendance_summaries(
                        $schoolId,
                        [(int) $studentId],
                        school_attendance_date_periods($liveAttendanceTerm, null, $schoolId)
                    )[(int) $studentId] ?? [];
                }
                $selectedReportCard['fee_summary'] = $entry['fee_summary'] === null
                    ? []
                    : json_decode((string) $entry['fee_summary'], true, 512, JSON_THROW_ON_ERROR);
            } else {
                $selectedReportCard['results'] = [];
                $selectedReportCard['ranked_student'] = null;
                $selectedReportCard['grading_scale'] = $gradeRules;
                $selectedReportCard['attendance'] = [];
                $selectedReportCard['fee_summary'] = [];
            }
            $noteStmt = db()->prepare(
                'SELECT teacher_comment, headmaster_remarks, student_behaviour, conduct, interest, attitude, next_term_date,
                        class_teacher_signature_name, headmaster_signature_name, proprietor_signature_name,
                        promotion_override, promotion_override_reason
                 FROM school_report_card_notes
                 WHERE school_id = ? AND batch_id = ? AND student_id = ? LIMIT 1'
            );
            $noteStmt->execute([$schoolId, (int) $selectedReportCard['id'], (int) $studentId]);
            $selectedReportCard['notes'] = $noteStmt->fetch() ?: [];
            if ($entry) {
                $classSizeStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_report_card_entries WHERE school_id = ? AND batch_id = ?'
                );
                $classSizeStmt->execute([$schoolId, (int) $selectedReportCard['id']]);
                $selectedReportCardDocument = [
                    'student_name' => (string) $entry['student_name'],
                    'admission_number' => (string) ($entry['admission_number'] ?? '—'),
                    'class_label' => school_class_label([
                        'level' => $selectedReportCard['class_level'],
                        'section' => $selectedReportCard['class_section'],
                    ]),
                    'academic_year' => (string) $selectedReportCard['academic_year'],
                    'term_name' => (string) $selectedReportCard['term_name'],
                    'assessment_name' => (string) $selectedReportCard['assessment_name'],
                    'generated_at' => (string) ($selectedReportCard['created_at'] ?? ''),
                    'submitted_at' => $selectedReportCard['submitted_at'] ?? null,
                    'reviewed_at' => $selectedReportCard['reviewed_at'] ?? null,
                    'published_at' => $selectedReportCard['published_at'] ?? null,
                    'total_score' => (float) $entry['total_score'],
                    'average_percent' => (float) $entry['average_percent'],
                    'overall_grade' => (string) $entry['overall_grade'],
                    'class_position' => $entry['class_position'] === null ? null : (int) $entry['class_position'],
                    'class_size' => (int) $classSizeStmt->fetchColumn(),
                    'subjects' => json_decode((string) $entry['subject_results'], true, 512, JSON_THROW_ON_ERROR),
                    'attendance' => $selectedReportCard['attendance']['term'] ?? [],
                    'fee_summary' => $selectedReportCard['fee_summary'],
                    'grading_scale' => $entry['grading_scale'] === null
                        ? $gradeRules
                        : json_decode((string) $entry['grading_scale'], true, 512, JSON_THROW_ON_ERROR),
                    'student_behaviour' => (string) ($selectedReportCard['notes']['student_behaviour'] ?? ''),
                    'conduct' => (string) ($selectedReportCard['notes']['conduct'] ?? $selectedReportCard['notes']['student_behaviour'] ?? ''),
                    'interest' => (string) ($selectedReportCard['notes']['interest'] ?? ''),
                    'attitude' => (string) ($selectedReportCard['notes']['attitude'] ?? ''),
                    'teacher_comment' => (string) ($selectedReportCard['notes']['teacher_comment'] ?? ''),
                    'headmaster_remarks' => (string) ($selectedReportCard['notes']['headmaster_remarks'] ?? ''),
                    'class_teacher_signature_name' => (string) ($selectedReportCard['notes']['class_teacher_signature_name'] ?? ''),
                    'headmaster_signature_name' => (string) ($selectedReportCard['notes']['headmaster_signature_name'] ?? ''),
                    'proprietor_signature_name' => (string) ($selectedReportCard['notes']['proprietor_signature_name'] ?? ''),
                    'promotion_override' => $selectedReportCard['notes']['promotion_override'] ?? null,
                    'promotion_override_reason' => (string) ($selectedReportCard['notes']['promotion_override_reason'] ?? ''),
                    'promotion_cutoff' => $reportPromotionCutoff,
                    'vacation_date' => (string) ($entry['vacation_date'] ?? ''),
                    'next_term_date' => (string) (($selectedReportCard['notes']['next_term_date'] ?? '') ?: school_report_card_next_term_date(
                        $schoolId,
                        (string) $selectedReportCard['academic_year'],
                        (string) $selectedReportCard['term_name']
                    )),
                ];
            }
        }

        if ($selectedReportCardDocument === null && !$studentPortal && !$parentPortal
            && $classId !== false && $classId > 0 && $selectedTerm !== null) {
            if ($assessmentName === '' || !in_array($assessmentName, $reportAssessmentOptions, true)) {
                $reportGenerationError = $reportAssessmentOptions === []
                    ? 'No reviewed or published subject results are available for this student in the selected class and term.'
                    : 'Select an available reviewed examination to generate the complete report card.';
            } else {
                try {
                    $reportGroup = school_assessment_group(
                        $schoolId,
                        $classId,
                        (string) $selectedTerm['academic_year'],
                        (string) $selectedTerm['term_name'],
                        $assessmentName
                    );
                    $groupStudent = null;
                    foreach ($reportGroup['students'] as $groupStudentRow) {
                        if ((int) $groupStudentRow['id'] === (int) $selectedStudent['id']) {
                            $groupStudent = $groupStudentRow;
                            break;
                        }
                    }
                    if ($groupStudent === null) {
                        throw new RuntimeException('The selected student is not enrolled in the validated report class.');
                    }

                    $reportSubjects = [];
                    $reportTotal = 0.0;
                    $approvedResultCount = 0;
                    foreach ($reportGroup['subjects'] as $reportSubject) {
                        $subjectScore = $groupStudent['subject_scores'][(int) $reportSubject['subject_id']] ?? null;
                        $isApproved = in_array((string) $reportSubject['status'], ['reviewed', 'published'], true);
                        $classScore = $isApproved && $subjectScore !== null && $subjectScore['class_score'] !== null
                            ? (float) $subjectScore['class_score']
                            : null;
                        $examScore = $isApproved && $subjectScore !== null && $subjectScore['exam_score'] !== null
                            ? (float) $subjectScore['exam_score']
                            : null;
                        $subjectTotal = $classScore !== null && $examScore !== null
                            ? round($classScore + $examScore, 2)
                            : null;
                        $grade = '';
                        $remark = '';
                        if ($subjectTotal !== null && $subjectScore !== null) {
                            $gradeRule = school_grade_rule_for_percent(
                                $schoolId,
                                ($subjectTotal / (float) $subjectScore['total_marks']) * 100
                            );
                            $grade = (string) $gradeRule['grade'];
                            $remark = trim((string) ($subjectScore['remarks'] ?? '')) !== ''
                                ? (string) $subjectScore['remarks']
                                : (string) $gradeRule['remark'];
                            $reportTotal += $subjectTotal;
                            $approvedResultCount++;
                        } else {
                            $reportHasIncompleteResults = true;
                        }
                        $reportSubjects[] = [
                            'subject_name' => (string) $reportSubject['subject_name'],
                            'class_score' => $classScore,
                            'exam_score' => $examScore,
                            'grade' => $grade,
                            'position' => $isApproved && $subjectScore !== null
                                ? ($subjectScore['position'] ?? null)
                                : null,
                            'remark' => $remark,
                        ];
                    }
                    $reportAttendance = school_attendance_summaries(
                        $schoolId,
                        [(int) $selectedStudent['id']],
                        school_attendance_date_periods($selectedTerm, null, $schoolId)
                    )[(int) $selectedStudent['id']]['term'] ?? [];
                    $selectedReportCardDocument = [
                        'student_name' => (string) $selectedStudent['full_name'],
                        'admission_number' => (string) ($selectedStudent['admission_number'] ?? '—'),
                        'class_label' => school_class_label([
                            'level' => $selectedStudent['class_level'],
                            'section' => $selectedStudent['class_section'],
                        ]),
                        'academic_year' => (string) $selectedTerm['academic_year'],
                        'term_name' => (string) $selectedTerm['term_name'],
                        'assessment_name' => $assessmentName,
                        'total_score' => $reportTotal,
                        'average_percent' => isset($groupStudent['average'])
                            ? (float) $groupStudent['average']
                            : 0.0,
                        'overall_grade' => (string) ($groupStudent['grade'] ?? ''),
                        'class_position' => $groupStudent['position'] ?? null,
                        'class_size' => count($reportGroup['students']),
                        'subjects' => $reportSubjects,
                        'attendance' => $reportAttendance,
                        'grading_scale' => $gradeRules,
                        'conduct' => '',
                        'interest' => '',
                        'attitude' => '',
                        'teacher_comment' => '',
                        'headmaster_remarks' => '',
                        'class_teacher_signature_name' => '',
                        'headmaster_signature_name' => '',
                        'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                        'vacation_date' => school_report_card_local_date(),
                        'next_term_date' => school_report_card_next_term_date(
                            $schoolId,
                            (string) $selectedTerm['academic_year'],
                            (string) $selectedTerm['term_name']
                        ),
                    ];
                    $generatedReportCard = true;
                    if ($approvedResultCount === 0) {
                        $reportHasIncompleteResults = true;
                        $reportGenerationError = 'No approved marks are available yet. Subject marks are left blank until results are reviewed.';
                    }
                } catch (Throwable $exception) {
                    error_log(
                        'Report-card preview generation failed: school_id=' . $schoolId
                        . ';class_id=' . $classId
                        . ';student_id=' . (int) $selectedStudent['id']
                        . ';term=' . $selectedTerm['term_name']
                        . ';assessment=' . $assessmentName
                        . ';error=' . $exception->getMessage()
                    );
                    $reportGenerationError = 'The complete report card could not be generated. Verify the school grading configuration and selected examination, then try again.';
                }
            }
        }
    }
}

$preparationGroup = null;
$showReportGeneration = $reportCardMode === 'generate' || (string) ($_GET['generate'] ?? '') === '1';
$showReportViewing = $reportCardMode === 'view';
$preparationClassId = filter_var($_GET['prepare_class_id'] ?? null, FILTER_VALIDATE_INT);
$preparationAcademicYear = trim((string) ($_GET['prepare_academic_year'] ?? $pageAcademicPeriod['academic_year'] ?? ''));
$preparationTerm = trim((string) ($_GET['prepare_term'] ?? $pageAcademicPeriod['term_name'] ?? ''));
$preparationAssessment = trim((string) ($_GET['prepare_assessment'] ?? '')) ?: 'End of Term Examination';
$preparationStudentId = filter_var($_GET['prepare_student_id'] ?? null, FILTER_VALIDATE_INT);
$preparationStudents = [];
if (!$studentPortal && $preparationClassId !== false && $preparationClassId > 0
    && $preparationAcademicYear !== '' && $preparationTerm !== '' && $preparationAssessment !== '') {
    $authorizedPreparationClass = null;
    foreach ($classes as $class) {
        if ((int) $class['id'] === $preparationClassId) {
            $authorizedPreparationClass = $class;
            break;
        }
    }
    if ($authorizedPreparationClass !== null && ($classTeacher || $reportCardAdmin)) {
        $classAcademicYear = (string) $authorizedPreparationClass['academic_year'];
        if ($preparationAcademicYear === $classAcademicYear) {
            $preparationStudents = array_values(array_filter(
                $students,
                static fn (array $student): bool => (int) ($student['class_id'] ?? 0) === $preparationClassId
                    && (string) ($student['academic_year'] ?? '') === $classAcademicYear
            ));
            $preparationGroup = school_assessment_group(
                $schoolId,
                $preparationClassId,
                $classAcademicYear,
                $preparationTerm,
                $preparationAssessment
            );
        }
    }
}

$managedBatch = null;
$managedEntries = [];
$managedReviewEntries = [];
$managedReviewProgress = ['total' => 0, 'remarks_saved' => 0, 'complete' => 0];
$managedTeacherProgress = ['total' => 0, 'remarks_saved' => 0, 'complete' => 0];
$focusedReviewIndex = null;
$editStudentId = filter_var($_GET['edit_student_id'] ?? null, FILTER_VALIDATE_INT);
$reportCardHistory = [];
if (!$studentPortal && ($classTeacher || $reportCardAdmin)) {
    $historySql =
        'SELECT batch.id, batch.academic_year, batch.term_name, batch.assessment_name,
                batch.status, batch.revision_number, batch.is_outdated, batch.created_at,
                batch.submitted_at, batch.reviewed_at, batch.published_at,
                batch.correction_reason, class.level, class.section,
                submitter.full_name AS submitted_by_name,
                (SELECT COUNT(*) FROM school_report_card_entries entry
                 WHERE entry.school_id = batch.school_id AND entry.batch_id = batch.id) AS student_count
         FROM school_report_card_batches batch
         JOIN school_classes class ON class.id = batch.class_id AND class.school_id = batch.school_id
         LEFT JOIN users submitter ON submitter.id = batch.submitted_by
         WHERE batch.school_id = ?
           AND batch.status IN ("draft", "ready", "submitted", "under_review", "reviewed", "correction_requested", "published")'
        . ($classTeacher ? ' AND EXISTS (
            SELECT 1 FROM teacher_class_assignments assignment
            WHERE assignment.school_id = batch.school_id AND assignment.class_id = batch.class_id
              AND assignment.teacher_user_id = ? AND assignment.academic_year = batch.academic_year
        )' : '')
        . ' ORDER BY batch.created_at DESC, batch.id DESC LIMIT 200';
    $historyStmt = db()->prepare($historySql);
    $historyStmt->execute($classTeacher ? [$schoolId, $teacherId] : [$schoolId]);
    $reportCardHistory = $historyStmt->fetchAll();
    if ($reportCardMode === 'review') {
        $reviewableStatuses = $classTeacher
            ? ['draft', 'ready', 'submitted', 'under_review', 'reviewed', 'correction_requested', 'published']
            : ($headmasterRole ? ['submitted', 'under_review'] : ['draft', 'ready', 'correction_requested']);
        $reportCardHistory = array_values(array_filter(
            $reportCardHistory,
            static fn (array $batch): bool => in_array((string) $batch['status'], $reviewableStatuses, true)
        ));
    } elseif ($reportCardMode === 'publish') {
        $reportCardHistory = $classTeacher
            ? array_values(array_filter(
                $reportCardHistory,
                static fn (array $batch): bool => (string) $batch['status'] === 'reviewed'
            ))
            : [];
    }
}
$manageBatchId = filter_var($_GET['batch_manage_id'] ?? null, FILTER_VALIDATE_INT);
$managedAttendanceSummaries = [];
if (!$studentPortal && $manageBatchId !== false && $manageBatchId > 0) {
    $manageStmt = db()->prepare(
        'SELECT batch.id, batch.class_id, batch.academic_year, batch.term_name,
                batch.assessment_name, batch.status, batch.revision_number,
                batch.is_outdated, batch.created_at, batch.submitted_at, batch.reviewed_at, batch.published_at,
                batch.correction_reason, class.level, class.section
         FROM school_report_card_batches batch
         JOIN school_classes class ON class.id = batch.class_id AND class.school_id = batch.school_id
         WHERE batch.id = ? AND batch.school_id = ? LIMIT 1'
    );
    $manageStmt->execute([$manageBatchId, $schoolId]);
    $managedBatch = $manageStmt->fetch() ?: null;
    if ($managedBatch && $classTeacher) {
        $managePermissionStmt = db()->prepare(
            'SELECT 1 FROM teacher_class_assignments
             WHERE school_id = ? AND class_id = ? AND teacher_user_id = ? AND academic_year = ? LIMIT 1'
        );
        $managePermissionStmt->execute([$schoolId, $managedBatch['class_id'], $teacherId, $managedBatch['academic_year']]);
        if (!$managePermissionStmt->fetchColumn()) {
            http_response_code(403);
            exit('This report-card batch is outside your assigned classes.');
        }
    } elseif ($managedBatch && !$reportCardAdmin) {
        http_response_code(403);
        exit('Your role cannot manage report-card batches.');
    }
    if ($managedBatch) {
        if ($editStudentId !== false && $editStudentId > 0) {
            $editStudentCheck = db()->prepare(
                'SELECT 1 FROM school_report_card_entries
                 WHERE school_id = ? AND batch_id = ? AND student_id = ? LIMIT 1'
            );
            $editStudentCheck->execute([$schoolId, $manageBatchId, $editStudentId]);
            if (!$editStudentCheck->fetchColumn()) {
                http_response_code(404);
                exit('The selected student is not part of this report-card batch.');
            }
        } else {
            $editStudentId = false;
        }
        $entriesStmt = db()->prepare(
            'SELECT entry.student_id, entry.student_name AS full_name, entry.admission_number,
                    entry.vacation_date,
                    entry.total_score, entry.average_percent,
                    entry.overall_grade, entry.class_position, entry.subject_results, entry.attendance_summary,
                    note.teacher_comment, note.headmaster_remarks, note.student_behaviour,
                        note.conduct, note.interest, note.attitude,
                        note.next_term_date, note.class_teacher_signature_name,
                    note.headmaster_signature_name, note.proprietor_signature_name,
                    note.promotion_override, note.promotion_override_reason
             FROM school_report_card_entries entry
             JOIN school_students student ON student.id = entry.student_id AND student.school_id = entry.school_id
             LEFT JOIN school_report_card_notes note ON note.school_id = entry.school_id
               AND note.batch_id = entry.batch_id AND note.student_id = entry.student_id
             WHERE entry.school_id = ? AND entry.batch_id = ?'
                . ($editStudentId !== false ? ' AND entry.student_id = ?' : '')
                . ' ORDER BY student.full_name'
        );
        $entriesStmt->execute($editStudentId !== false
            ? [$schoolId, $manageBatchId, $editStudentId]
            : [$schoolId, $manageBatchId]);
        $managedEntries = $entriesStmt->fetchAll();
        $reviewNavigationStmt = db()->prepare(
            'SELECT entry.student_id, entry.student_name, entry.vacation_date,
                    note.teacher_comment, note.class_teacher_signature_name,
                    note.headmaster_remarks, note.headmaster_signature_name
             FROM school_report_card_entries entry
             LEFT JOIN school_report_card_notes note
               ON note.school_id = entry.school_id AND note.batch_id = entry.batch_id
              AND note.student_id = entry.student_id
             WHERE entry.school_id = ? AND entry.batch_id = ?
             ORDER BY entry.student_name, entry.student_id'
        );
        $reviewNavigationStmt->execute([$schoolId, $manageBatchId]);
        $managedReviewEntries = $reviewNavigationStmt->fetchAll();
        $classTeacherCanEditStatus = $classTeacher
            && in_array((string) $managedBatch['status'], ['draft', 'ready', 'correction_requested'], true)
            && !(bool) $managedBatch['is_outdated'];
        $headmasterCanEditStatus = $headmasterRole
            && in_array((string) $managedBatch['status'], ['submitted', 'under_review'], true)
            && !(bool) $managedBatch['is_outdated'];
        if ($editStudentId === false && $managedReviewEntries !== []
            && ($classTeacherCanEditStatus || $headmasterCanEditStatus)) {
            $editStudentId = (int) $managedReviewEntries[0]['student_id'];
            $managedEntries = array_values(array_filter(
                $managedEntries,
                static fn(array $entry): bool => (int) $entry['student_id'] === $editStudentId
            ));
        }
        foreach ($managedReviewEntries as $reviewIndex => $reviewEntry) {
            $hasRemark = trim((string) ($reviewEntry['headmaster_remarks'] ?? '')) !== '';
            $hasSignature = trim((string) ($reviewEntry['headmaster_signature_name'] ?? '')) !== '';
            $hasVacationDate = trim((string) ($reviewEntry['vacation_date'] ?? '')) !== '';
            $managedReviewProgress['total']++;
            if ($hasRemark) {
                $managedReviewProgress['remarks_saved']++;
            }
            if ($hasRemark && $hasSignature && $hasVacationDate) {
                $managedReviewProgress['complete']++;
            }
            $teacherHasRemark = trim((string) ($reviewEntry['teacher_comment'] ?? '')) !== '';
            $teacherHasSignature = trim((string) ($reviewEntry['class_teacher_signature_name'] ?? '')) !== '';
            $managedTeacherProgress['total']++;
            if ($teacherHasRemark) {
                $managedTeacherProgress['remarks_saved']++;
            }
            if ($teacherHasRemark && $teacherHasSignature) {
                $managedTeacherProgress['complete']++;
            }
            if ($editStudentId !== false && (int) $reviewEntry['student_id'] === $editStudentId) {
                $focusedReviewIndex = $reviewIndex;
            }
        }
        $managedTermStmt = db()->prepare(
            'SELECT id, academic_year_id, school_id, academic_year, term_name, starts_on, ends_on
             FROM school_terms
             WHERE school_id = ? AND academic_year = ? AND term_name = ?
             ORDER BY starts_on DESC LIMIT 1'
        );
        $managedTermStmt->execute([
            $schoolId,
            (string) $managedBatch['academic_year'],
            (string) $managedBatch['term_name'],
        ]);
        $managedAttendanceTerm = $managedTermStmt->fetch() ?: null;
        if ($managedAttendanceTerm !== null) {
            $managedAttendanceSummaries = school_attendance_summaries(
                $schoolId,
                array_column($managedEntries, 'student_id'),
                school_attendance_date_periods($managedAttendanceTerm, null, $schoolId)
            );
        }
    }
}

if ((string) ($_GET['download'] ?? '') === 'html') {
    if (!$selectedStudent || !$selectedReportCardDocument
        || (!$selectedReportCard && !$generatedReportCard)) {
        http_response_code(404);
        exit('The complete report card is not available.');
    }
    $downloadName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $selectedReportCardDocument['student_name']) ?: 'student';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report-card-' . $downloadName . '.html"');
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><title>End-of-term report · <?= e($selectedReportCardDocument['student_name']) ?></title>
    <style>
        *{box-sizing:border-box}
        body{font:10pt/1.25 Arial,sans-serif;color:#172331;margin:10mm auto;max-width:210mm;padding:0 5mm}
        .terminal-report-card{display:grid;gap:3mm;width:min(100%,210mm);aspect-ratio:210/297;padding:8mm;border:3px double #167c75;background:#f5f9fd;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-report-header{display:grid;gap:2mm;padding:3mm;border-bottom:1px solid #167c75;text-align:center;background:linear-gradient(110deg,#12365c,#167c75);color:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-school-identity-row{display:flex;flex-direction:column;gap:1mm;align-items:center;text-align:center}
        .terminal-school-logo-slot{display:grid;place-items:center;min-height:18mm}
        .terminal-school-logo{width:18mm;height:18mm;object-fit:contain}
        .terminal-school-name-address{display:grid;width:100%;gap:.5mm;justify-items:center;text-align:center}
        .terminal-school-name-address strong{color:#fff;font-size:14pt;font-weight:900}
        .terminal-school-name-address span{color:#f3f6fa;font-size:7pt}
        .terminal-report-title,.terminal-report-year{margin:0;color:#fff;font-weight:bold}
        .terminal-report-title{font-size:10pt;font-weight:500;white-space:normal}.terminal-report-year{font-size:8pt;font-weight:400;text-align:center}
        .report-card-section{display:grid;gap:2mm}
        .terminal-metadata-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2mm 4mm}
        .terminal-metadata-grid>div{display:grid;gap:1mm;padding:1.5mm;border:1px solid #d5e4ef;border-left:3px solid #3988c4;border-radius:1mm;background:#eaf3fa;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-metadata-grid span,.terminal-behaviour-grid strong{color:#12365c;font-size:7pt;font-weight:bold;text-transform:uppercase}
        .terminal-metadata-grid strong{font-size:8pt}
        table{width:100%;border-collapse:collapse}
        .terminal-mark-table{table-layout:fixed;font-size:8pt}
        .report-card-table-scroll{width:100%;overflow-x:auto}
        .terminal-mark-table .terminal-mark-subject{width:18%}
        .terminal-mark-table .terminal-mark-class-score,.terminal-mark-table .terminal-mark-exam-score{width:13%}
        .terminal-mark-table .terminal-mark-total{width:11%}
        .terminal-mark-table .terminal-mark-grade{width:8%}
        .terminal-mark-table .terminal-mark-position{width:11%}
        .terminal-mark-table .terminal-mark-remarks{width:26%}
        th,td{padding:1mm;border:1px solid #374151;text-align:left;vertical-align:top;overflow-wrap:anywhere}
        th{color:#12365c;font-size:7.5pt;white-space:normal;text-align:center}
        .terminal-mark-table thead th{border-color:#12365c;color:#fff;background:#174875;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-mark-table tbody tr:nth-child(even) td{background:#f0f6fa;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-mark-table tbody td:nth-child(4){color:#155b3b;background:#e6f4eb;font-weight:bold;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-mark-table tbody td:nth-child(5){color:#76520a;background:#fff4d6;font-weight:bold;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-mark-table th:nth-child(n+2):nth-child(-n+6),.terminal-mark-table td:nth-child(n+2):nth-child(-n+6){text-align:center}
        .terminal-mark-table td:nth-child(5),.terminal-mark-table td:nth-child(6){white-space:nowrap}
        .terminal-mark-table th:last-child,.terminal-mark-table td:last-child{overflow-wrap:break-word;word-break:normal}
        .terminal-summary-strip{display:flex;gap:1mm 3mm;padding:1mm 0;border:1px solid #a8cdb5;border-radius:1mm;background:#eaf6ee;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-behaviour-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:3mm}
        .terminal-behaviour-grid>div{display:grid;grid-template-columns:auto 1fr;gap:2mm;padding:1mm;border:1px solid #d3e7da;border-radius:1mm;background:#edf7f0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-comments-grid,.terminal-signatures{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:3mm}
        .terminal-comments-grid>div{min-height:16mm;padding:2mm;border:1px solid #b9cfdf;background:#f0f6fa;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .terminal-comments-grid h2{margin:0 0 1mm;color:#167c75;font-size:6pt}
        .terminal-comments-grid p{margin:0;white-space:pre-wrap}
        .terminal-signatures{grid-template-columns:repeat(3,minmax(0,1fr))}
        .terminal-signatures>div{display:grid;gap:.7mm;text-align:center;font-size:7.5pt}
        .terminal-signatures span{font-size:7pt;font-weight:600;line-height:1.1}
        .terminal-signatures strong{min-height:1em}
        .signature-box{min-height:8mm;border-bottom:1px solid #374151}
        .terminal-signatures>div:last-child .signature-box{min-height:10mm;border:1px solid #374151}
        @page report{size:A4 portrait;margin:5mm}
        @media print{
            body{page:report;margin:0;max-width:none;padding:0}
            .terminal-report-card{display:grid;zoom:var(--report-print-scale,1);break-inside:avoid;page-break-inside:avoid;gap:1.5mm;width:100%;padding:4mm;border-width:2px;font-size:8pt;line-height:1.12}
            .terminal-report-card h1,.terminal-report-card h2,.terminal-report-card h3,.terminal-report-card h4{margin:0!important;line-height:1.05!important}
            .terminal-report-header{gap:.3mm;padding:.6mm;background:linear-gradient(110deg,#12365c,#167c75)!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .terminal-school-name-address strong,.terminal-school-name-address span,.terminal-report-title,.terminal-report-year{color:#fff!important}
            .terminal-school-name-address strong{font-size:14pt}
            .terminal-school-name-address span{font-size:7pt}
            .terminal-report-title{font-size:10pt}
            .terminal-report-year{font-size:8pt}
            .terminal-school-logo-slot{min-height:12mm}
            .terminal-school-logo{width:12mm;height:12mm}
            .terminal-metadata-grid{gap:.3mm .7mm}
            .terminal-metadata-grid>div{gap:.15mm;padding:.3mm 0}
            .terminal-metadata-grid span{font-size:7pt}
            .terminal-metadata-grid strong{font-size:8pt}
            .report-card-table-scroll{overflow:visible}
            .terminal-mark-table{width:100%;min-width:0;table-layout:fixed;font-size:8pt}
            .terminal-mark-table th,.terminal-mark-table td{padding:.6mm .5mm;line-height:1.1}
            .terminal-mark-table thead th{font-size:7.5pt;white-space:normal;text-align:center}
            .terminal-mark-table td:nth-child(2),.terminal-mark-table td:nth-child(3),.terminal-mark-table td:nth-child(4),.terminal-mark-table td:nth-child(5),.terminal-mark-table td:nth-child(6){white-space:nowrap}
            .terminal-summary-strip{gap:.8mm 2mm;padding:.8mm 0}
            .terminal-summary-strip span{font-size:7.5pt}
            .terminal-comments-grid>div{min-height:10mm;padding:1mm}
            .terminal-report-card .terminal-comments-grid h2,.terminal-report-card .terminal-comments-grid h4{margin:0 0 .5mm!important;font-size:7.5pt!important;font-weight:700!important;line-height:1.1!important}
            .terminal-report-card .terminal-comments-grid p{font-size:7.5pt!important;line-height:1.15!important}
            .terminal-signatures{grid-template-columns:repeat(3,minmax(0,1fr));gap:1.2mm}
            .terminal-signatures>div{gap:.7mm;font-size:7.5pt;line-height:1.1}
            .terminal-report-card .terminal-signatures span{font-size:7pt!important;font-weight:600!important;line-height:1.1!important}
            .terminal-report-card .terminal-signatures strong{font-size:7.5pt!important;line-height:1.1!important;overflow-wrap:anywhere}
            .signature-box{min-height:8mm}
            .terminal-signatures>div:last-child .signature-box{min-height:10mm}
        }
    </style></head><body>
    <?= school_report_card_document($selectedReportCardDocument, $school) ?>
    <script>
        const fitReportCardToPage = () => {
            const card = document.querySelector('.terminal-report-card');
            if (!card) return;
            card.style.removeProperty('--report-print-scale');
            const height = Math.max(card.getBoundingClientRect().height, card.scrollHeight);
            if (height > 0) card.style.setProperty('--report-print-scale', String(Math.min(1, (280 * 96 / 25.4) / height)));
        };
        window.addEventListener('beforeprint', fitReportCardToPage);
        window.addEventListener('afterprint', () => document.querySelector('.terminal-report-card')?.style.removeProperty('--report-print-scale'));
    </script>
    </body></html>
    <?php
    exit;
}

$batchPrintId = filter_var($_GET['batch_print_id'] ?? null, FILTER_VALIDATE_INT);
if ($batchPrintId !== false && $batchPrintId > 0) {
    if ($studentPortal || $parentPortal) {
        http_response_code(403);
        exit('Whole-class report cards are restricted to authorized school staff.');
    }
    $printBatchStmt = db()->prepare(
        'SELECT batch.id, batch.class_id, batch.academic_year, batch.term_name,
                batch.assessment_name, batch.status, batch.revision_number,
                batch.is_outdated, batch.created_at, batch.submitted_at, batch.reviewed_at,
                batch.published_at, class.level, class.section
         FROM school_report_card_batches batch
         JOIN school_classes class ON class.id = batch.class_id AND class.school_id = batch.school_id
         WHERE batch.id = ? AND batch.school_id = ?
           AND batch.status IN ("draft", "ready", "submitted", "under_review", "reviewed", "correction_requested", "published")
         LIMIT 1'
    );
    $printBatchStmt->execute([$batchPrintId, $schoolId]);
    $printBatch = $printBatchStmt->fetch();
    if (!$printBatch) {
        http_response_code(404);
        exit('The requested report-card batch is not available.');
    }
    if ($classTeacher) {
        $printPermissionStmt = db()->prepare(
            'SELECT 1 FROM teacher_class_assignments
             WHERE school_id = ? AND class_id = ? AND teacher_user_id = ? AND academic_year = ? LIMIT 1'
        );
        $printPermissionStmt->execute([$schoolId, $printBatch['class_id'], $teacherId, $printBatch['academic_year']]);
        if (!$printPermissionStmt->fetchColumn()) {
            http_response_code(403);
            exit('This report-card batch is outside your assigned classes.');
        }
    } elseif (!$reportCardAdmin) {
        http_response_code(403);
        exit('Your role cannot print report-card batches.');
    }
    $printEntriesStmt = db()->prepare(
        'SELECT entry.student_id, entry.student_name, entry.admission_number, entry.vacation_date,
                entry.total_score, entry.average_percent, entry.overall_grade,
                entry.class_position, entry.subject_results, entry.attendance_summary, entry.grading_scale,
                entry.fee_summary,
                note.teacher_comment, note.headmaster_remarks, note.student_behaviour,
                note.conduct, note.interest, note.attitude,
                note.next_term_date, note.class_teacher_signature_name,
                note.headmaster_signature_name, note.proprietor_signature_name,
                note.promotion_override, note.promotion_override_reason
         FROM school_report_card_entries entry
         LEFT JOIN school_report_card_notes note ON note.school_id = entry.school_id
           AND note.batch_id = entry.batch_id AND note.student_id = entry.student_id
         WHERE entry.school_id = ? AND entry.batch_id = ?
         ORDER BY entry.student_name, entry.student_id'
    );
    $printEntriesStmt->execute([$schoolId, $batchPrintId]);
    $printEntries = $printEntriesStmt->fetchAll();
    if ($printEntries === []) {
        http_response_code(404);
        exit('This report-card batch has no saved student snapshots.');
    }
    $printTermStmt = db()->prepare(
        'SELECT id, school_id, academic_year, term_name, starts_on, ends_on
         FROM school_terms
         WHERE school_id = ? AND academic_year = ? AND term_name = ?
         ORDER BY starts_on DESC LIMIT 1'
    );
    $printTermStmt->execute([
        $schoolId,
        (string) $printBatch['academic_year'],
        (string) $printBatch['term_name'],
    ]);
    $printAttendanceTerm = $printTermStmt->fetch() ?: null;
    $printAttendanceSummaries = $printAttendanceTerm === null
        ? []
        : school_attendance_summaries(
            $schoolId,
            array_column($printEntries, 'student_id'),
            school_attendance_date_periods($printAttendanceTerm, null, $schoolId)
        );
    $printNextTermDate = school_report_card_next_term_date(
        $schoolId,
        (string) $printBatch['academic_year'],
        (string) $printBatch['term_name']
    );
    $printClassLabel = school_class_label(['level' => $printBatch['level'], 'section' => $printBatch['section']]);
    render_header('Whole-class report cards');
    ?>
    <section class="panel report-card-page report-card-batch">
        <h1>Whole-class report cards</h1>
        <p><?= e(school_class_label(['level' => $printBatch['level'], 'section' => $printBatch['section']])) ?>
            · <?= e((string) $printBatch['academic_year']) ?> · <?= e((string) $printBatch['term_name']) ?>
            · <?= e((string) $printBatch['assessment_name']) ?> · Revision <?= (int) $printBatch['revision_number'] ?>
            · <?= e(school_report_card_batch_status_label((string) $printBatch['status'])) ?></p>
        <?php if ((bool) $printBatch['is_outdated']): ?>
            <p class="notice notice-error">This is a preserved historical snapshot. Marks changed after it was generated.</p>
        <?php endif; ?>
        <button class="button button-primary" type="button" data-print-button>Print / Save as PDF</button>
        <?php foreach ($printEntries as $printEntry): ?>
            <?php
            $printAttendance = $printAttendanceSummaries[(int) $printEntry['student_id']]['term'] ?? [];
            $printReport = [
                'student_name' => (string) $printEntry['student_name'],
                'admission_number' => (string) ($printEntry['admission_number'] ?? '—'),
                'class_label' => $printClassLabel,
                'academic_year' => (string) $printBatch['academic_year'],
                'term_name' => (string) $printBatch['term_name'],
                'assessment_name' => (string) $printBatch['assessment_name'],
                'total_score' => (float) $printEntry['total_score'],
                'average_percent' => (float) $printEntry['average_percent'],
                'overall_grade' => (string) $printEntry['overall_grade'],
                'class_position' => $printEntry['class_position'] === null ? null : (int) $printEntry['class_position'],
                'class_size' => count($printEntries),
                'subjects' => json_decode((string) $printEntry['subject_results'], true, 512, JSON_THROW_ON_ERROR),
                'attendance' => is_array($printAttendance) ? $printAttendance : [],
                'fee_summary' => $printEntry['fee_summary'] === null
                    ? []
                    : json_decode((string) $printEntry['fee_summary'], true, 512, JSON_THROW_ON_ERROR),
                'grading_scale' => $printEntry['grading_scale'] === null
                    ? $gradeRules
                    : json_decode((string) $printEntry['grading_scale'], true, 512, JSON_THROW_ON_ERROR),
                'student_behaviour' => (string) ($printEntry['student_behaviour'] ?? ''),
                'conduct' => (string) ($printEntry['conduct'] ?? $printEntry['student_behaviour'] ?? ''),
                'interest' => (string) ($printEntry['interest'] ?? ''),
                'attitude' => (string) ($printEntry['attitude'] ?? ''),
                'teacher_comment' => (string) ($printEntry['teacher_comment'] ?? ''),
                'headmaster_remarks' => (string) ($printEntry['headmaster_remarks'] ?? ''),
                'class_teacher_signature_name' => (string) ($printEntry['class_teacher_signature_name'] ?? ''),
                'headmaster_signature_name' => (string) ($printEntry['headmaster_signature_name'] ?? ''),
                'proprietor_signature_name' => (string) ($printEntry['proprietor_signature_name'] ?? ''),
                'promotion_override' => $printEntry['promotion_override'] ?? null,
                'promotion_override_reason' => (string) ($printEntry['promotion_override_reason'] ?? ''),
                'promotion_cutoff' => $reportPromotionCutoff,
                'vacation_date' => (string) ($printEntry['vacation_date'] ?? ''),
                'next_term_date' => (string) (($printEntry['next_term_date'] ?? '') ?: $printNextTermDate),
                'generated_at' => (string) $printBatch['created_at'],
                'submitted_at' => $printBatch['submitted_at'] ?? null,
                'reviewed_at' => $printBatch['reviewed_at'] ?? null,
                'published_at' => $printBatch['published_at'] ?? null,
            ];
            ?>
            <?= school_report_card_document($printReport, $school) ?>
        <?php endforeach; ?>
    </section>
    <?php
    render_footer();
    exit;
}

render_header('Report cards');
?>
<section class="panel">
    <p class="eyebrow">REPORTS</p>
    <h1>Student report cards</h1>
    <p>Choose a report-card task to continue. Report access and editing remain restricted to your role and school.</p>
</section>

<?php if ($classTeacher || $reportCardAdmin || $studentPortal || $parentPortal): ?>
<section class="report-action-grid report-card-management-actions" aria-label="Available report-card actions" data-action-return="report_cards.php" data-action-start="<?= e($managedBatch ? 'report-view' : ($selectedStudent && $selectedReportCardDocument ? 'report-view' : (in_array($reportCardMode, ['review', 'publish'], true) ? 'report-history' : ((($reportCardMode === 'generate' || $showReportGeneration) && $canPrepareReportCards) ? 'report-generate' : ($reportCardMode === 'view' ? 'report-batch' : ''))))) ?>">
    <?php if ($canPrepareReportCards): ?>
        <article class="report-action-card report-workflow-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('receipt') ?></span>
            <div><h2>Generate Report Cards</h2><p>Choose a class and examination to review result readiness and generate the class report-card batch.</p></div>
            <a class="button button-primary" href="report_cards.php?mode=generate#report-generate" data-action-open="report-generate">Generate Report Cards</a>
        </article>
        <article class="report-action-card report-workflow-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('results') ?></span>
            <div>
                <h2><?= $classTeacher ? 'Send Reports to Headmaster' : 'Report-card History' ?></h2>
                <?php if ($classTeacher): ?>
                    <p class="muted">The Headmaster adds remarks and a signature for each report. The review date is recorded automatically when review is completed.</p>
                <?php else: ?>
                    <p>Open saved report batches and review their current workflow status.</p>
                <?php endif; ?>
            </div>
            <a class="button" href="report_cards.php?mode=review"><?= $classTeacher ? 'Open My Report Batches' : 'Open Reports' ?></a>
        </article>
        <?php if ($classTeacher): ?>
            <article class="report-action-card report-workflow-action-card">
                <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('publish') ?></span>
                <div><h2>Reviewed Reports — Ready to Release</h2><p>Open batches returned by the Headmaster and release their reports to linked student and parent accounts.</p></div>
                <a class="button button-primary" href="report_cards.php?mode=publish">Open Ready Batches</a>
            </article>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($headmasterRole): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('results') ?></span>
            <div><h2>Reports Awaiting Review</h2><p>Open a submitted batch, add a remark and signature for each student, and save your changes. When every report is complete, choose <strong>Complete Review and Return to Class Teacher</strong>. The review date is recorded automatically.</p></div>
            <a class="button button-primary" href="report_cards.php?mode=review">Review Reports</a>
        </article>
    <?php endif; ?>
    <?php if ($studentPortal || $parentPortal): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('receipt') ?></span>
            <div><h2><?= $studentPortal ? 'My Released Report Cards' : 'Children’s Released Report Cards' ?></h2><p>View reports after the assigned Class Teacher releases them.</p></div>
            <a class="button button-primary" href="report_cards.php?mode=view#report-batch" data-action-open="report-batch">View Reports</a>
        </article>
    <?php endif; ?>
    <article class="report-action-card report-action-card-secondary">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('book') ?></span>
        <div><h2>Report Card Layout</h2><p>Preview the printable report-card layout without changing school records.</p></div>
        <a class="button" href="report_card_sample.php">Preview Layout</a>
    </article>
</section>
<?php endif; ?>

<?php if (!$studentPortal && ($classTeacher || $reportCardAdmin) && $canPrepareReportCards): ?>
<section class="panel report-card-section" id="report-generate" data-action-view="report-generate">
    <h2>Prepare Report Cards</h2>
    <?php if (!$showReportGeneration): ?>
        <p class="muted">Use the Generate Report Cards action to review results readiness and prepare reports for a class.</p>
    <?php else: ?>
    <p class="muted">A batch is available only after all required subject marks are submitted and reviewed. Generated results and attendance are saved as a historical snapshot.</p>
    <form method="get" action="report_cards.php" class="form-stack">
        <input type="hidden" name="generate" value="1">
        <div class="form-grid two-columns">
            <div><label for="prepare-academic-year">Academic year</label><select id="prepare-academic-year" name="prepare_academic_year" required><option value="">Select academic year</option>
                <?php foreach ($reportAcademicYears as $academicYear): ?><option value="<?= e($academicYear) ?>" <?= $preparationAcademicYear === $academicYear ? 'selected' : '' ?>><?= e(school_academic_year_label($academicYear)) ?></option><?php endforeach; ?>
            </select></div>
            <div><label for="prepare-class">Class</label><select id="prepare-class" name="prepare_class_id" required><option value="">Select class</option>
                <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= $preparationClassId !== false && (int) $class['id'] === $preparationClassId ? 'selected' : '' ?>><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option><?php endforeach; ?>
            </select></div>
            <div><label for="prepare-term">Term</label><select id="prepare-term" name="prepare_term" required>
                <option value="">Select term</option>
                <?php foreach (school_standard_term_names() as $standardTerm): ?>
                    <option value="<?= e($standardTerm) ?>" <?= $preparationTerm === $standardTerm ? 'selected' : '' ?>><?= e($standardTerm) ?></option>
                <?php endforeach; ?>
                <?php if ($preparationTerm !== '' && !in_array($preparationTerm, school_standard_term_names(), true)): ?><option value="<?= e($preparationTerm) ?>" selected><?= e($preparationTerm) ?> (historical)</option><?php endif; ?>
            </select></div>
            <div><label for="prepare-assessment">Examination</label><input id="prepare-assessment" name="prepare_assessment" value="<?= e($preparationAssessment) ?>" maxlength="120" required></div>
            <div><label for="prepare-student-selection">Student <span class="label-hint">(optional)</span></label><select id="prepare-student-selection" name="prepare_student_id"><option value="">All students in selected class</option>
                <?php foreach ($preparationStudents as $preparationStudent): ?><option value="<?= (int) $preparationStudent['id'] ?>" <?= $preparationStudentId !== false && (int) $preparationStudent['id'] === $preparationStudentId ? 'selected' : '' ?>><?= e((string) $preparationStudent['full_name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <button class="button" type="submit">Check results readiness</button>
    </form>
    <?php if ($preparationGroup !== null): ?>
        <p><strong>Results status:</strong> <?= e((string) $preparationGroup['status']) ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr>
                    <th>Student / individual record</th>
                    <th>Admission number</th>
                    <?php foreach ($preparationGroup['subjects'] as $subject): ?>
                        <th><?= e((string) $subject['subject_name']) ?><br><small><?= e(ucfirst(str_replace('_', ' ', (string) $subject['status']))) ?> · <?= (int) $subject['missing_results'] ?> missing</small></th>
                    <?php endforeach; ?>
                    <th>Total</th><th>Average</th><th>Grade</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($preparationGroup['students'] as $resultStudent): ?>
                        <tr>
                            <th scope="row">
                                <?= e((string) $resultStudent['full_name']) ?>
                                <details><summary>Review subject records</summary>
                                    <ul>
                                        <?php foreach ($preparationGroup['subjects'] as $subject): ?>
                                            <?php $individualScore = $resultStudent['subject_scores'][(int) $subject['subject_id']] ?? null; ?>
                                            <li>
                                                <?= e((string) $subject['subject_name']) ?>:
                                                <?php if ($individualScore === null): ?>
                                                    <?= e(ucfirst(str_replace('_', ' ', (string) $subject['status']))) ?> — mark missing
                                                <?php else: ?>
                                                    Class <?= $individualScore['class_score'] === null ? '—' : e(number_format((float) $individualScore['class_score'], 2)) ?>
                                                    · Examination <?= e(number_format((float) $individualScore['exam_score'], 2)) ?>
                                                    · Total <?= e(number_format((float) $individualScore['score'], 2)) ?>/<?= e(number_format((float) $individualScore['total_marks'], 2)) ?>
                                                    · <?= e((string) $individualScore['grade']) ?> · <?= e((string) $individualScore['remarks']) ?>
                                                    · Subject position <?= e(school_rank_position_label(isset($individualScore['position']) ? (int) $individualScore['position'] : null)) ?>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </th>
                            <td><?= e((string) ($resultStudent['admission_number'] ?? '—')) ?></td>
                            <?php foreach ($preparationGroup['subjects'] as $subject): ?>
                                <?php $subjectScore = $resultStudent['subject_scores'][(int) $subject['subject_id']] ?? null; ?>
                                <td>
                                    <?php if ($subjectScore === null): ?>
                                        <strong>Missing</strong>
                                    <?php else: ?>
                                        <?= e(number_format((float) $subjectScore['score'], 2)) ?>/<?= e(number_format((float) $subjectScore['total_marks'], 2)) ?>
                                        · <?= e((string) $subjectScore['grade']) ?>
                                        · <?= e(school_rank_position_label(isset($subjectScore['position']) ? (int) $subjectScore['position'] : null)) ?>
                                        <small>(<?= e(ucfirst(str_replace('_', ' ', (string) $subject['status']))) ?>)</small>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><?= $resultStudent['average'] === null ? 'Incomplete' : e(number_format((float) $resultStudent['total'], 2)) ?></td>
                            <td><?= $resultStudent['average'] === null ? '—' : e(number_format((float) $resultStudent['average'], 2)) . '%' ?></td>
                            <td><?= e((string) ($resultStudent['grade'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($preparationGroup['ready_for_report_cards']): ?>
            <form method="post" action="report_cards.php" class="top-gap">
                <?= csrf_field() ?><input type="hidden" name="action" value="prepare_report_cards">
                <input type="hidden" name="class_id" value="<?= (int) $preparationClassId ?>">
                <input type="hidden" name="term_name" value="<?= e($preparationTerm) ?>">
                <input type="hidden" name="assessment_name" value="<?= e($preparationAssessment) ?>">
                <label for="prepare-student">Open a student report after preparing the class batch <span class="label-hint">(optional)</span></label>
                <select id="prepare-student" name="student_id">
                    <option value="">Prepare and open the whole class batch</option>
                    <?php foreach ($preparationGroup['students'] as $resultStudent): ?>
                        <option value="<?= (int) $resultStudent['id'] ?>" <?= $preparationStudentId !== false && (int) $resultStudent['id'] === $preparationStudentId ? 'selected' : '' ?>><?= e((string) $resultStudent['full_name']) ?><?= !empty($resultStudent['admission_number']) ? ' · ' . e((string) $resultStudent['admission_number']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button button-primary" type="submit">Prepare Reports</button>
            </form>
        <?php else: ?>
            <p class="muted">Outstanding subjects:</p>
            <?php if ($preparationGroup['outstanding_subjects'] === []): ?><p class="muted">All subject marks are present; authorized review is still required.</p>
            <?php else: ?><ul><?php foreach ($preparationGroup['outstanding_subjects'] as $outstanding): ?><li><?= e((string) $outstanding['subject_name']) ?> · <?= e((string) $outstanding['status']) ?> · <?= (int) $outstanding['missing_results'] ?> marks missing · teacher: <?= e((string) $outstanding['teacher_name']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($classTeacher || $headmasterRole || $proprietorRole): ?>
<section class="panel report-card-section" id="report-history" data-action-view="report-history">
    <details <?= in_array($reportCardMode, ['review', 'publish'], true) ? 'open' : '' ?>>
        <summary><?= $reportCardMode === 'publish'
            ? 'Reviewed Reports — Ready to Release'
            : ($headmasterRole
                ? 'Submitted reports for Headmaster review'
                : ($classTeacher && $reportCardMode === 'review' ? 'My report batches' : 'Report-card history and revisions')) ?></summary>
        <?php if ($reportCardHistory === []): ?>
            <p class="muted"><?= $reportCardMode === 'publish'
                ? 'No reviewed reports are waiting to be released. A batch appears here after the Headmaster completes review and returns it; batches still submitted or under review are listed under My report batches.'
                : ($headmasterRole && $reportCardMode === 'review'
                    ? 'No report batches are currently awaiting review.'
                    : ($headmasterRole
                        ? 'No reports are currently waiting for Headmaster review.'
                        : ($classTeacher && $reportCardMode === 'review'
                            ? 'No report batches are available for your assigned classes.'
                            : 'No report-card snapshots match this task for your authorized classes.'))) ?></p>
        <?php else: ?>
            <?php
            $reportBatchStatusChart = [];
            foreach ($reportCardHistory as $historyBatch) {
                $statusLabel = school_report_card_batch_status_label((string) $historyBatch['status']);
                $reportBatchStatusChart[$statusLabel] = ($reportBatchStatusChart[$statusLabel] ?? 0) + 1;
            }
            ?>
            <p class="muted">Workflow status across up to 200 of the most recently created report-card batches available in your authorized view.</p>
            <?php render_dashboard_bar_chart('Report-card batches by workflow status · authorized classes', $reportBatchStatusChart, 'batches'); ?>
            <div class="table-scroll"><table>
                <thead><tr><th>Academic year / term</th><th>Class</th><th>Examination</th><th>Revision</th><th>Students</th><th>Submitted by</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($reportCardHistory as $historyBatch): ?>
                        <tr>
                            <td><?= e((string) $historyBatch['academic_year']) ?> · <?= e((string) $historyBatch['term_name']) ?></td>
                            <td><?= e(school_class_label(['level' => $historyBatch['level'], 'section' => $historyBatch['section']])) ?></td>
                            <td><?= e((string) $historyBatch['assessment_name']) ?></td>
                            <td><?= (int) $historyBatch['revision_number'] ?></td>
                            <td><?= (int) $historyBatch['student_count'] ?></td>
                            <td><?= e((string) ($historyBatch['submitted_by_name'] ?? '—')) ?></td>
                            <td><?= e($historyBatch['submitted_at'] !== null
                                ? school_report_card_format_datetime((string) $historyBatch['submitted_at'], true)
                                : 'Not submitted') ?></td>
                            <td><?= e(school_report_card_batch_status_label((string) $historyBatch['status'])) ?><?= (bool) $historyBatch['is_outdated'] ? ' · outdated' : '' ?></td>
                            <td><a href="report_cards.php?batch_manage_id=<?= (int) $historyBatch['id'] ?>">Open batch</a> · <a href="report_cards.php?batch_print_id=<?= (int) $historyBatch['id'] ?>">Print class</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </details>
</section>
<?php endif; ?>

<?php if ($managedBatch !== null): ?>
<section class="panel report-card-section" id="report-view" data-action-view="report-view">
    <?php
    $managedClassLabel = school_class_label([
        'level' => $managedBatch['level'],
        'section' => $managedBatch['section'],
    ]);
    $managedBatchContext = $managedClassLabel . ', ' . (string) $managedBatch['academic_year']
        . ', ' . (string) $managedBatch['term_name'];
    ?>
    <h2><?= e((string) $managedBatch['term_name']) ?> report-card batch · <?= e((string) $managedBatch['assessment_name']) ?></h2>
    <p class="muted"><?= e(school_class_label(['level' => $managedBatch['level'], 'section' => $managedBatch['section']])) ?> · <?= e((string) $managedBatch['academic_year']) ?> · Revision <?= (int) $managedBatch['revision_number'] ?> · Status: <?= e(school_report_card_batch_status_label((string) $managedBatch['status'])) ?><?= (bool) $managedBatch['is_outdated'] ? ' · OUTDATED — marks changed after this snapshot was generated' : '' ?></p>
    <?php if (!empty($managedBatch['correction_reason'])): ?><p class="notice">Correction requested: <?= e((string) $managedBatch['correction_reason']) ?></p><?php endif; ?>
    <?php if ($classTeacher && in_array((string) $managedBatch['status'], ['draft', 'ready', 'correction_requested'], true)): ?>
        <p class="notice">Complete and save the Class Teacher remark and signature for every student, then submit the entire batch to the Headmaster. The submit button becomes available when all reports are complete.</p>
    <?php elseif ($classTeacher && in_array((string) $managedBatch['status'], ['submitted', 'under_review'], true)): ?>
        <p class="notice">This entire batch has already been submitted to the Headmaster. Class Teacher remarks and signatures are locked while the Headmaster enters separate remarks and a signature for each student. You can open and preview reports, but cannot edit or submit this batch again.</p>
    <?php elseif ($classTeacher && $managedBatch['status'] === 'reviewed'): ?>
        <p class="notice">The Headmaster has completed and returned this batch. Review and preview the reports, then release the whole batch to linked students and parents.</p>
    <?php elseif ($classTeacher && $managedBatch['status'] === 'published'): ?>
        <p class="notice">This batch has already been released to linked student and parent accounts. The released reports are locked.</p>
    <?php elseif ($headmasterRole && in_array((string) $managedBatch['status'], ['submitted', 'under_review'], true)): ?>
        <p class="notice">Enter and save the Headmaster remark, signature, and vacation date for each student. Class Teacher remarks and signatures are preserved and cannot be changed during Headmaster review.</p>
    <?php endif; ?>
    <p class="muted">Report generated: <?= e(school_report_card_format_datetime((string) $managedBatch['created_at'])) ?>
        <?php if (!empty($managedBatch['submitted_at'])): ?> · Submitted: <?= e(school_report_card_format_datetime((string) $managedBatch['submitted_at'], true)) ?><?php endif; ?>
        <?php if (!empty($managedBatch['reviewed_at'])): ?> · Reviewed: <?= e(school_report_card_format_datetime((string) $managedBatch['reviewed_at'], true)) ?><?php endif; ?>
        <?php if (!empty($managedBatch['published_at'])): ?> · Released: <?= e(school_report_card_format_datetime((string) $managedBatch['published_at'], true)) ?><?php endif; ?></p>
    <?php if (($headmasterRole && in_array((string) $managedBatch['status'], ['submitted', 'under_review'], true)
            || $classTeacher && in_array((string) $managedBatch['status'], ['draft', 'ready', 'correction_requested'], true))
        && !(bool) $managedBatch['is_outdated']): ?>
        <?php
        $teacherPreparation = $classTeacher
            && in_array((string) $managedBatch['status'], ['draft', 'ready', 'correction_requested'], true);
        $progress = $teacherPreparation ? $managedTeacherProgress : $managedReviewProgress;
        $progressRemaining = max(0, $progress['total'] - $progress['complete']);
        $incompleteReportNames = array_values(array_map(
            static fn(array $entry): string => (string) $entry['student_name'],
            array_filter(
                $managedReviewEntries,
                static fn(array $entry): bool => $teacherPreparation
                    ? trim((string) ($entry['teacher_comment'] ?? '')) === ''
                        || trim((string) ($entry['class_teacher_signature_name'] ?? '')) === ''
                    : trim((string) ($entry['headmaster_remarks'] ?? '')) === ''
                        || trim((string) ($entry['headmaster_signature_name'] ?? '')) === ''
                        || trim((string) ($entry['vacation_date'] ?? '')) === ''
            )
        ));
        ?>
        <section class="report-review-progress" aria-labelledby="report-review-progress-heading">
            <h3 id="report-review-progress-heading"><?= $teacherPreparation ? 'Class Teacher completion progress' : 'Headmaster review progress' ?></h3>
            <div class="stat-grid">
                <article class="stat-card"><?php dashboard_section_heading('reports', 'Reports completed'); ?><strong><?= $progress['complete'] ?> of <?= $progress['total'] ?></strong></article>
                <article class="stat-card"><?php dashboard_section_heading('receipt', 'Reports remaining'); ?><strong><?= $progressRemaining ?></strong></article>
                <article class="stat-card"><?php dashboard_section_heading('profile', 'Reports with saved remarks'); ?><strong><?= $progress['remarks_saved'] ?></strong></article>
            </div>
            <?php if ($incompleteReportNames !== []): ?>
                <p class="notice notice-warning"><?= $teacherPreparation ? 'Reports needing a Class Teacher remark and signature:' : 'Reports awaiting a Headmaster remark, signature, or vacation date:' ?></p>
                <ul><?php foreach ($incompleteReportNames as $incompleteReportName): ?><li><?= e($incompleteReportName) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($managedReviewEntries !== []): ?>
                <nav class="report-review-navigation" aria-label="Navigate student reports">
                    <?php if ($focusedReviewIndex !== null): ?>
                        <?php if ($focusedReviewIndex > 0): $previousReview = $managedReviewEntries[$focusedReviewIndex - 1]; ?>
                            <button class="button" type="submit" form="report-card-notes-form" name="navigate_to_student_id" value="<?= (int) $previousReview['student_id'] ?>">Save and Previous Student</button>
                        <?php endif; ?>
                        <span>Report <?= $focusedReviewIndex + 1 ?> of <?= count($managedReviewEntries) ?></span>
                        <?php if ($focusedReviewIndex < count($managedReviewEntries) - 1): $nextReview = $managedReviewEntries[$focusedReviewIndex + 1]; ?>
                            <button class="button" type="submit" form="report-card-notes-form" name="navigate_to_student_id" value="<?= (int) $nextReview['student_id'] ?>">Save and Next Student</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="report-review-student-links">
                        <?php foreach ($managedReviewEntries as $reviewIndex => $reviewEntry): ?>
                            <span <?= $focusedReviewIndex === $reviewIndex ? 'aria-current="step"' : '' ?>>
                                <?= e((string) $reviewEntry['student_name']) ?>
                                <?php if ($teacherPreparation): ?>
                                    <?= trim((string) ($reviewEntry['teacher_comment'] ?? '')) !== ''
                                        && trim((string) ($reviewEntry['class_teacher_signature_name'] ?? '')) !== '' ? ' · complete' : ' · incomplete' ?>
                                <?php else: ?>
                                    <?= trim((string) ($reviewEntry['headmaster_remarks'] ?? '')) !== ''
                                        && trim((string) ($reviewEntry['headmaster_signature_name'] ?? '')) !== ''
                                        && trim((string) ($reviewEntry['vacation_date'] ?? '')) !== '' ? ' · reviewed' : ' · awaiting review' ?>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php
    $managedNextTermDate = school_report_card_next_term_date($schoolId, (string) $managedBatch['academic_year'], (string) $managedBatch['term_name']);
    $classTeacherCanEditBatch = $classTeacher
        && in_array((string) $managedBatch['status'], ['draft', 'ready', 'correction_requested'], true)
        && !(bool) $managedBatch['is_outdated'];
    $headmasterCanEditBatch = $headmasterRole
        && in_array((string) $managedBatch['status'], ['submitted', 'under_review'], true)
        && !(bool) $managedBatch['is_outdated'];
    $proprietorCanEditBatch = $proprietorRole && in_array((string) $managedBatch['status'], ['draft', 'ready', 'correction_requested'], true);
    $canEditBatchNotes = $classTeacherCanEditBatch || $headmasterCanEditBatch || $proprietorCanEditBatch;
    ?>
    <div class="attendance-tools">
        <a class="button" href="report_cards.php?batch_print_id=<?= (int) $managedBatch['id'] ?>">Print / Save whole-class report cards as PDF</a>
        <?php if ($classTeacher && in_array((string) $managedBatch['status'], ['draft', 'ready', 'correction_requested'], true) && !(bool) $managedBatch['is_outdated']): ?>
            <form method="post" action="report_cards.php?batch_manage_id=<?= (int) $managedBatch['id'] ?>" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="submit_report_cards"><input type="hidden" name="batch_id" value="<?= (int) $managedBatch['id'] ?>">
                <button class="button button-primary" type="submit" data-confirm="<?= e('Submit all ' . (int) $managedTeacherProgress['total'] . ' reports for ' . $managedBatchContext . ' to the Headmaster for review?') ?>" <?= $managedTeacherProgress['total'] < 1 || $managedTeacherProgress['complete'] !== $managedTeacherProgress['total'] ? 'disabled' : '' ?>>Submit All Reports to Headmaster</button>
            </form>
        <?php endif; ?>
        <?php if ($headmasterRole && in_array((string) $managedBatch['status'], ['submitted', 'under_review'], true) && !(bool) $managedBatch['is_outdated']): ?>
            <form method="post" action="report_cards.php?batch_manage_id=<?= (int) $managedBatch['id'] ?>" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="complete_review_return"><input type="hidden" name="batch_id" value="<?= (int) $managedBatch['id'] ?>">
                <button class="button button-primary" type="submit" data-confirm="<?= e('Complete review for all ' . (int) $managedReviewProgress['total'] . ' reports in ' . $managedBatchContext . ' and return the batch to the Class Teacher?') ?>" <?= $managedReviewProgress['complete'] < 1 || $managedReviewProgress['complete'] !== $managedReviewProgress['total'] ? 'disabled' : '' ?>>Complete Review and Return to Class Teacher</button>
            </form>
            <p class="muted">Save a Headmaster remark and signature for every student first. The review date is recorded automatically when review is completed.</p>
        <?php endif; ?>
    </div>
    <?php if (!$canEditBatchNotes && $managedReviewEntries !== []): ?>
        <section class="panel">
            <h3>Students in this batch</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Student</th><th>Class Teacher</th><th>Headmaster</th><th>Report</th></tr></thead>
                    <tbody>
                        <?php foreach ($managedReviewEntries as $reviewEntry): ?>
                            <?php
                            $teacherReportComplete = trim((string) ($reviewEntry['teacher_comment'] ?? '')) !== ''
                                && trim((string) ($reviewEntry['class_teacher_signature_name'] ?? '')) !== '';
                            $headmasterReportComplete = trim((string) ($reviewEntry['headmaster_remarks'] ?? '')) !== ''
                                && trim((string) ($reviewEntry['headmaster_signature_name'] ?? '')) !== ''
                                && trim((string) ($reviewEntry['vacation_date'] ?? '')) !== '';
                            ?>
                            <tr>
                                <td><?= e((string) $reviewEntry['student_name']) ?></td>
                                <td><?= $teacherReportComplete ? 'Complete' : 'Incomplete' ?></td>
                                <td><?= $headmasterReportComplete ? 'Complete' : 'Incomplete' ?></td>
                                <td><a href="report_cards.php?student_id=<?= (int) $reviewEntry['student_id'] ?>&amp;batch_id=<?= (int) $managedBatch['id'] ?>">View report</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($canEditBatchNotes): ?>
    <section id="edit-report-details" class="panel<?= $classTeacherCanEditBatch ? ' class-teacher-report-editor' : ($headmasterCanEditBatch ? ' headmaster-report-editor' : '') ?>" data-report-card-editor>
        <h2><?= $classTeacherCanEditBatch ? 'Edit This Student\'s Report Card' : ($headmasterCanEditBatch ? 'Review This Student\'s Report Card' : 'Edit Report Details') ?></h2>
        <p class="muted"><?= $classTeacherCanEditBatch
            ? 'Enter the Class Teacher remark and signature for this student, then save before moving to the next report.'
            : ($headmasterCanEditBatch
                ? 'Enter your separate Headmaster remark, signature, and vacation date, then save before moving to the next report.'
                : 'Make and save authorized report-card changes for this batch.') ?></p>
    <form id="report-card-notes-form" method="post" action="report_cards.php?batch_manage_id=<?= (int) $managedBatch['id'] ?>#edit-report-details" class="form-stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_report_card_notes"><input type="hidden" name="batch_id" value="<?= (int) $managedBatch['id'] ?>">
        <?php if ($editStudentId !== false): ?><input type="hidden" name="edit_student_id" value="<?= (int) $editStudentId ?>"><?php endif; ?>
        <?php foreach ($managedEntries as $entry): ?>
            <article class="panel">
                <h3><?= e((string) $entry['full_name']) ?></h3>
                <p class="muted">Admission number: <?= e((string) ($entry['admission_number'] ?? '—')) ?></p>
                <?php
                $subjectResults = json_decode((string) $entry['subject_results'], true, 512, JSON_THROW_ON_ERROR);
                $teacherRemark = (string) ($entry['teacher_comment'] ?? '');
                $remarkSuggestion = school_report_card_remark_suggestion(
                    $subjectResults,
                    $headmasterCanEditBatch,
                    $teacherRemark
                );
                ?>
                <?php if ($classTeacherCanEditBatch): ?>
                    <label for="teacher-comment-<?= (int) $entry['student_id'] ?>">CLASS TEACHER’S REMARK</label>
                    <textarea id="teacher-comment-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][teacher_comment]" rows="4" maxlength="1000" autofocus><?= e((string) ($entry['teacher_comment'] ?? '')) ?></textarea>
                    <?php if (trim((string) ($entry['teacher_comment'] ?? '')) === ''): ?>
                        <?php if ($remarkSuggestion !== null): ?>
                            <p class="muted">Suggested draft based only on this student’s saved subject results. Review or edit it; it is not saved until you choose Save Changes.</p>
                            <button class="button button-quiet" type="button" data-remark-suggestion data-remark-target="teacher-comment-<?= (int) $entry['student_id'] ?>" data-remark-text="<?= e($remarkSuggestion) ?>">Use suggested remark</button>
                        <?php else: ?>
                            <p class="muted">There are not enough recorded subject results to suggest a remark. Enter the Class Teacher remark manually.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <label for="class-teacher-signature-<?= (int) $entry['student_id'] ?>">CLASS TEACHER’S SIGNATURE NAME</label>
                    <input id="class-teacher-signature-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][class_teacher_signature_name]" maxlength="160" value="<?= e((string) ($entry['class_teacher_signature_name'] ?? '')) ?>">
                    <details class="report-card-optional-teacher-fields">
                        <summary>Other report details (optional)</summary>
                        <label for="student-conduct-<?= (int) $entry['student_id'] ?>">CONDUCT</label>
                        <input id="student-conduct-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][conduct]" maxlength="160" value="<?= e((string) ($entry['conduct'] ?? $entry['student_behaviour'] ?? '')) ?>">
                        <label for="student-interest-<?= (int) $entry['student_id'] ?>">INTEREST</label>
                        <input id="student-interest-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][interest]" maxlength="160" value="<?= e((string) ($entry['interest'] ?? '')) ?>">
                        <label for="student-attitude-<?= (int) $entry['student_id'] ?>">ATTITUDE</label>
                        <input id="student-attitude-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][attitude]" maxlength="160" value="<?= e((string) ($entry['attitude'] ?? '')) ?>">
                        <label for="next-term-date-<?= (int) $entry['student_id'] ?>">NEXT TERM BEGINS</label>
                        <input id="next-term-date-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][next_term_date]" type="date" value="<?= e((string) (($entry['next_term_date'] ?? '') ?: $managedNextTermDate)) ?>">
                    </details>
                <?php endif; ?>
                <?php if ($headmasterCanEditBatch): ?>
                    <label for="headmaster-remarks-<?= (int) $entry['student_id'] ?>">HEADMASTER’S REMARKS</label>
                    <textarea id="headmaster-remarks-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][headmaster_remarks]" rows="4" maxlength="1000" autofocus><?= e((string) ($entry['headmaster_remarks'] ?? '')) ?></textarea>
                    <?php if (trim((string) ($entry['headmaster_remarks'] ?? '')) === ''): ?>
                        <?php if ($remarkSuggestion !== null): ?>
                            <p class="muted">Suggested draft based on this student’s saved results and the Class Teacher’s saved remark. Review or edit it; it is not saved until you choose Save Changes.</p>
                            <button class="button button-quiet" type="button" data-remark-suggestion data-remark-target="headmaster-remarks-<?= (int) $entry['student_id'] ?>" data-remark-text="<?= e($remarkSuggestion) ?>">Use suggested remark</button>
                        <?php else: ?>
                            <p class="muted">There are not enough recorded results or no saved Class Teacher remark to suggest a Headmaster remark. Enter it manually.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <label for="headmaster-signature-<?= (int) $entry['student_id'] ?>">HEADMASTER’S SIGNATURE NAME</label>
                    <input id="headmaster-signature-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][headmaster_signature_name]" maxlength="160" value="<?= e((string) ($entry['headmaster_signature_name'] ?? '')) ?>" placeholder="<?= e((string) ($user['full_name'] ?? '')) ?>">
                    <label for="vacation-date-<?= (int) $entry['student_id'] ?>">DATE OF VACATION</label>
                    <input id="vacation-date-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][vacation_date]" type="date" value="<?= e((string) ($entry['vacation_date'] ?? '')) ?>" required>
                <?php endif; ?>
                <p>Total <?= e(number_format((float) $entry['total_score'], 2)) ?> · Average <?= e(number_format((float) $entry['average_percent'], 2)) ?>% · Grade <?= e((string) $entry['overall_grade']) ?> · Position <?= $entry['class_position'] === null ? 'Not ranked' : (int) $entry['class_position'] ?></p>
                <div class="report-card-section">
                    <div class="terminal-metadata-grid">
                        <div><span>Academic year</span><strong><?= e((string) $managedBatch['academic_year']) ?></strong></div>
                        <div><span>Term</span><strong><?= e((string) $managedBatch['term_name']) ?></strong></div>
                        <div><span>Date of vacation</span><strong><?= e(!empty($entry['vacation_date']) ? date('d M Y', strtotime((string) $entry['vacation_date'])) : 'Not set') ?></strong></div>
                        <div><span>Next term begins</span><strong><?= e($managedNextTermDate !== '' ? date('d M Y', strtotime($managedNextTermDate)) : 'Not configured') ?></strong></div>
                    </div>
                </div>
                <div class="table-scroll">
                    <table class="terminal-mark-table">
                        <colgroup>
                            <col class="terminal-mark-subject">
                            <col class="terminal-mark-class-score">
                            <col class="terminal-mark-exam-score">
                            <col class="terminal-mark-total">
                            <col class="terminal-mark-grade">
                            <col class="terminal-mark-position">
                            <col class="terminal-mark-remarks">
                        </colgroup>
                        <thead><tr><th>SUBJECT</th><th>CLASS (30%)</th><th>EXAMS (70%)</th><th>TOTAL (100%)</th><th>GRADE</th><th>POSITION</th><th>REMARKS</th></tr></thead>
                        <tbody>
                            <?php foreach ($subjectResults as $subjectResult): ?>
                                <?php
                                $editClassScore = isset($subjectResult['class_score']) ? (float) $subjectResult['class_score'] : null;
                                $editExamScore = isset($subjectResult['exam_score']) ? (float) $subjectResult['exam_score'] : null;
                                $editTotalScore = $editClassScore !== null && $editExamScore !== null
                                    ? $editClassScore + $editExamScore
                                    : null;
                                ?>
                                <tr>
                                    <td><?= e((string) $subjectResult['subject_name']) ?></td>
                                    <td><?= $editClassScore === null ? '—' : e(school_report_score_label($editClassScore)) ?></td>
                                    <td><?= $editExamScore === null ? '—' : e(school_report_score_label($editExamScore)) ?></td>
                                    <td><?= $editTotalScore === null ? '—' : e(school_report_score_label($editTotalScore)) ?></td>
                                    <td><?= e((string) (($subjectResult['grade'] ?? '') ?: '—')) ?></td>
                                    <td><?= e(school_rank_position_label(isset($subjectResult['position']) ? (int) $subjectResult['position'] : null)) ?></td>
                                    <td><?= e((string) (($subjectResult['remark'] ?? '') ?: '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $entryAttendanceSummary = $managedAttendanceSummaries[(int) $entry['student_id']]['term'] ?? [];
                ?>
                <p class="muted">Attendance: <?= isset($entryAttendanceSummary['present'], $entryAttendanceSummary['scheduled_days'])
                    ? (empty($entryAttendanceSummary['incomplete'])
                        ? (int) $entryAttendanceSummary['present'] . ' days present out of ' . (int) $entryAttendanceSummary['scheduled_days'] . ' school days'
                            . ($entryAttendanceSummary['percentage'] !== null ? ' · ' . number_format((float) $entryAttendanceSummary['percentage'], 2) . '%' : '')
                        : 'Provisional · ' . (int) $entryAttendanceSummary['recorded_days'] . ' of ' . (int) $entryAttendanceSummary['scheduled_days'] . ' school days recorded')
                    : 'Not recorded' ?></p>
                <a class="text-link" href="report_cards.php?student_id=<?= (int) $entry['student_id'] ?>&amp;batch_id=<?= (int) $managedBatch['id'] ?>">Preview / print report card →</a>
                <?php if ($proprietorCanEditBatch): ?>
                    <label for="proprietor-signature-<?= (int) $entry['student_id'] ?>">PROPRIETOR / DIRECTOR’S SIGNATURE NAME</label>
                    <input id="proprietor-signature-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][proprietor_signature_name]" maxlength="160" value="<?= e((string) ($entry['proprietor_signature_name'] ?? '')) ?>" <?= $managedBatch['status'] === 'published' ? 'disabled' : '' ?>>
                <?php endif; ?>
                <?php if ($proprietorCanEditBatch || $headmasterCanEditBatch): ?>
                    <label for="promotion-override-<?= (int) $entry['student_id'] ?>">Authorized promotion override</label>
                    <select id="promotion-override-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][promotion_override]" <?= $managedBatch['status'] === 'published' ? 'disabled' : '' ?>>
                        <option value="">Use default ranking rule</option>
                        <option value="promoted" <?= ($entry['promotion_override'] ?? '') === 'promoted' ? 'selected' : '' ?>>Promoted</option>
                        <option value="pending" <?= ($entry['promotion_override'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                    <label for="promotion-reason-<?= (int) $entry['student_id'] ?>">Override reason (required when overriding)</label>
                    <textarea id="promotion-reason-<?= (int) $entry['student_id'] ?>" name="notes[<?= (int) $entry['student_id'] ?>][promotion_override_reason]" rows="2" maxlength="500" <?= $managedBatch['status'] === 'published' ? 'disabled' : '' ?>><?= e((string) ($entry['promotion_override_reason'] ?? '')) ?></textarea>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <div class="attendance-tools">
            <button class="button button-primary" type="submit">Save Changes</button>
            <a class="button button-quiet" href="report_cards.php?mode=review#report-history">Cancel</a>
        </div>
    </form>
    </section>
    <?php endif; ?>
    <?php if ($headmasterRole && in_array((string) $managedBatch['status'], ['submitted', 'under_review'], true) && !(bool) $managedBatch['is_outdated']): ?>
        <form method="post" action="report_cards.php?batch_manage_id=<?= (int) $managedBatch['id'] ?>" class="top-gap form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="return_report_cards"><input type="hidden" name="batch_id" value="<?= (int) $managedBatch['id'] ?>">
            <label for="report-correction-reason">Return batch for correction (reason required)</label>
            <textarea id="report-correction-reason" name="reason" rows="2" maxlength="500" required></textarea>
            <button class="button" type="submit" data-confirm="Return the complete batch to the Class Teacher for correction? Saved Headmaster remarks will be retained.">Return Batch for Correction</button>
        </form>
    <?php endif; ?>
    <?php if ($classTeacher && $managedBatch['status'] === 'reviewed' && !(bool) $managedBatch['is_outdated']): ?>
        <form method="post" action="report_cards.php?batch_manage_id=<?= (int) $managedBatch['id'] ?>" class="top-gap">
            <?= csrf_field() ?><input type="hidden" name="action" value="publish_report_cards"><input type="hidden" name="batch_id" value="<?= (int) $managedBatch['id'] ?>">
            <p class="muted">Headmaster review is complete. Recheck the batch and confirm before releasing reports to linked student and parent accounts.</p>
            <button class="button button-primary" type="submit" data-confirm="<?= e('Release all ' . (int) $managedReviewProgress['total'] . ' reviewed reports for ' . $managedBatchContext . ' to linked student and parent accounts?') ?>"
                <?= $managedReviewProgress['total'] < 1 || $managedReviewProgress['complete'] !== $managedReviewProgress['total'] ? 'disabled' : '' ?>>Release Reports to Students and Parents</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($studentPortal || $parentPortal || (!$classTeacher && !$reportCardAdmin) || ($canPrepareReportCards) || $showReportViewing): ?>
<section class="panel report-card-section" data-action-view="report-batch">
    <h2><?= $studentPortal ? 'My released report card' : ($parentPortal ? 'My children\'s released report cards' : 'Open a student report') ?></h2>
    <?php if (!$studentPortal && $students === []): ?><p class="notice">No eligible students are available in your assigned school classes for the selected academic period.</p><?php endif; ?>
    <form method="get" action="report_cards.php" class="form-stack" data-report-card-selector>
        <?php if ($canPrepareReportCards): ?><input type="hidden" name="mode" value="generate"><?php endif; ?>
        <?php if ($showReportViewing): ?><input type="hidden" name="mode" value="view"><?php endif; ?>
        <?php if (count($reportAssessmentOptions) > 1): ?>
            <div>
                <label for="report-assessment-name">Approved Examination</label>
                <select id="report-assessment-name" name="assessment_name" data-report-assessment required>
                    <option value="">Select examination</option>
                    <?php foreach ($reportAssessmentOptions as $reportAssessmentOption): ?>
                        <option value="<?= e($reportAssessmentOption) ?>" <?= $assessmentName === $reportAssessmentOption ? 'selected' : '' ?>><?= e($reportAssessmentOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php elseif ($assessmentName !== ''): ?>
            <input type="hidden" name="assessment_name" value="<?= e($assessmentName) ?>">
        <?php endif; ?>
        <?php if ($studentPortal): ?><input type="hidden" name="student_id" value="<?= (int) $studentId ?>"><?php endif; ?>
        <div class="form-grid two-columns">
            <div>
                <label for="report-academic-year">Academic Year</label>
                <select id="report-academic-year" name="academic_year" data-report-year required>
                    <option value="">Select academic year</option>
                    <?php foreach ($reportAcademicYears as $academicYear): ?>
                        <option value="<?= e($academicYear) ?>" <?= $selectedAcademicYear === $academicYear ? 'selected' : '' ?>><?= e(school_academic_year_label($academicYear)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="term_id">Academic Term</label>
                <select id="term_id" name="term_id" data-report-term required>
                    <option value="">Select term</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>" data-academic-year="<?= e((string) $term['academic_year']) ?>" <?= $selectedTerm && (int) $selectedTerm['id'] === (int) $term['id'] ? 'selected' : '' ?>><?= e((string) $term['term_name']) ?> · <?= e(school_academic_year_label((string) $term['academic_year'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="report-class">Class</label>
                <select id="report-class" name="class_id" data-report-class required>
                    <option value="">Select class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" data-academic-year="<?= e((string) $class['academic_year']) ?>" <?= $classId !== false && (int) $classId === (int) $class['id'] ? 'selected' : '' ?>><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$studentPortal): ?><div>
                <label for="student_id">Student</label>
                <select id="student_id" name="student_id" data-report-student required>
                    <option value="">Select student</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>" data-class-id="<?= (int) $student['class_id'] ?>" data-academic-year="<?= e((string) ($student['academic_year'] ?? '')) ?>" <?= ($studentId !== false && (int) $student['id'] === $studentId) ? 'selected' : '' ?>><?= e((string) $student['full_name']) ?><?= $student['class_level'] ? ' · ' . e(school_class_label(['level' => $student['class_level'], 'section' => $student['class_section']])) : '' ?><?= $student['school_status'] !== 'active' ? ' · Former student' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div><?php endif; ?>
        </div>
        <p class="muted" data-report-selector-help aria-live="polite">Select an academic year, term, class, student, and examination where prompted. The report opens when your selections are complete.</p>
    </form>
</section>
<?php endif; ?>

<?php if ($selectedStudent && $selectedReportCardDocument
    && ($showReportViewing || ($showReportGeneration && $canPrepareReportCards)
        || (isset($_GET['student_id']) && (string) ($_GET['student_id'] ?? '') !== ''))): ?>
    <section class="panel report-card-page individual-report-page report-card-section" data-action-view="report-view">
        <?php if ((bool) ($selectedReportCard['is_outdated'] ?? false)): ?>
            <p class="notice notice-error">This report card is an outdated historical snapshot. Marks changed after it was generated.</p>
        <?php endif; ?>
        <?php if ($generatedReportCard): ?>
            <p class="notice">Live preview from reviewed subject results. This preview does not save a report-card batch or change examination records.</p>
            <?php if ($reportHasIncompleteResults): ?><p class="notice">Missing, incomplete, or unreviewed subject results are shown as blank; no marks have been invented.</p><?php endif; ?>
            <?php if ($reportGenerationError !== ''): ?><p class="notice"><?= e($reportGenerationError) ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if (($selectedReportCard['status'] ?? 'published') !== 'published'): ?>
            <p class="notice">Preview only — this report card has not been released.</p>
        <?php endif; ?>
        <div class="attendance-tools">
            <?php if (!$studentPortal && ($classTeacher || $reportCardAdmin) && in_array((string) ($selectedReportCard['status'] ?? ''), ['draft', 'ready', 'correction_requested'], true)): ?>
                <a class="button" href="report_cards.php?batch_manage_id=<?= (int) $selectedReportCard['id'] ?>&amp;edit_student_id=<?= (int) $studentId ?>#edit-report-details">Edit Report Details</a>
            <?php elseif ($generatedReportCard && ($classTeacher || $reportCardAdmin)): ?>
                <form method="post" action="report_cards.php" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="prepare_report_cards">
                    <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
                    <input type="hidden" name="term_name" value="<?= e((string) $selectedTerm['term_name']) ?>">
                    <input type="hidden" name="assessment_name" value="<?= e($assessmentName) ?>">
                    <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
                    <button class="button" type="submit">Edit Report Details</button>
                </form>
            <?php endif; ?>
            <button class="button button-primary" type="button" data-print-button>Print / Download PDF</button>
            <?php if (($selectedReportCard['status'] ?? '') === 'published' || $generatedReportCard): ?>
                <?php if ($generatedReportCard): ?>
                    <a class="button" href="report_cards.php?student_id=<?= (int) $studentId ?>&amp;academic_year=<?= rawurlencode((string) $selectedTerm['academic_year']) ?>&amp;term_id=<?= (int) $selectedTerm['id'] ?>&amp;class_id=<?= (int) $classId ?>&amp;assessment_name=<?= rawurlencode($assessmentName) ?>&amp;download=html">Download HTML report card</a>
                <?php else: ?>
                    <a class="button" href="report_cards.php?student_id=<?= (int) $studentId ?>&amp;batch_id=<?= (int) $selectedReportCard['id'] ?>&amp;download=html">Download HTML report card</a>
                <?php endif; ?>
                <?php
                $sharePhone = preg_replace('/\D+/', '', (string) ($selectedStudent['guardian_phone'] ?? '')) ?? '';
                if (($classTeacher || $reportCardAdmin) && strlen($sharePhone) >= 8 && strlen($sharePhone) <= 15):
                    $shareMessage = rawurlencode('Hello, I am sharing the student report card with you. Please attach the downloaded report file to this WhatsApp conversation.');
                ?>
                    <a class="button" href="https://wa.me/<?= e($sharePhone) ?>?text=<?= e($shareMessage) ?>" target="_blank" rel="noopener noreferrer">Share with Parent</a>
                    <small class="muted">Download the report first, then attach the PDF in WhatsApp. The share link does not attach files automatically.</small>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?= school_report_card_document($selectedReportCardDocument, $school) ?>
    </section>
<?php endif; ?>

<?php if ($selectedStudent && $selectedReportCard && !$selectedReportCardDocument): ?>
    <section class="panel notice notice-error top-gap">
        The selected report-card snapshot has no matching saved entry. Please contact a school administrator.
    </section>
<?php endif; ?>

<?php if ($selectedStudent && !$selectedReportCardDocument && !$selectedReportCard): ?>
    <section class="panel report-card-page report-card-section">
        <h2>Complete report card not available</h2>
        <p class="notice"><?= e($reportGenerationError !== '' ? $reportGenerationError : 'Select a valid academic year, term, class, student, and approved examination to generate the complete report card.') ?></p>
        <?php if ($selectedReportCard): $rankedStudent = $selectedReportCard['ranked_student']; $reportSubjectRows = is_array($selectedReportCard['results'] ?? null) ? $selectedReportCard['results'] : []; $schoolAddress = trim((string) ($school['address'] ?? '')); $schoolRegion = trim((string) ($school['region'] ?? '')); $schoolDistrict = trim((string) ($school['district'] ?? '')); $schoolContactLine = trim((string) (($school['contact_phone'] ?? '') . (!empty($school['contact_email']) ? (!empty($school['contact_phone']) ? ' · ' : '') . $school['contact_email'] : ''))); $termClosingDate = !empty($reportCardTerm['ends_on']) ? date('d M Y', strtotime((string) $reportCardTerm['ends_on'])) : 'Not configured'; $nextTermReopening = $reportCardNextReopening; $reportAttendance = is_array($selectedReportCard['attendance'] ?? null) ? ($selectedReportCard['attendance']['term'] ?? null) : null; ?>
            <?php if ((bool) ($selectedReportCard['is_outdated'] ?? false)): ?>
                <p class="notice notice-error">This released report card is an outdated historical snapshot. Marks changed after it was issued; select the newer released revision when available.</p>
            <?php endif; ?>
            <section class="report-card-output terminal-report-card">
                <div class="terminal-report-header">
                    <div class="terminal-school-brand">
                        <?= school_identity_markup($school, false, 'compact') ?>
                        <?php if ($schoolAddress !== ''): ?><div class="terminal-school-address"><?= e($schoolAddress) ?></div><?php endif; ?>
                        <?php if ($schoolRegion !== '' || $schoolDistrict !== ''): ?><div class="terminal-school-meta"><?= e(trim($schoolRegion . ($schoolRegion !== '' && $schoolDistrict !== '' ? ', ' : '') . $schoolDistrict)) ?></div><?php endif; ?>
                        <?php if ($schoolContactLine !== ''): ?><div class="terminal-school-meta"><?= e($schoolContactLine) ?></div><?php endif; ?>
                    </div>
                    <div class="terminal-report-title-block">
                        <div class="terminal-report-title">STUDENT ACADEMIC REPORT</div>
                        <div class="terminal-report-level"><?= e($selectedReportCard['class_level'] ? school_class_label(['level' => $selectedReportCard['class_level'], 'section' => $selectedReportCard['class_section']]) : 'Class Level') ?></div>
                    </div>
                </div>

                <div class="terminal-student-summary">
                    <div class="terminal-metadata-grid">
                        <div><span>Student name</span><strong><?= e((string) ($selectedReportCard['student_name'] ?? $selectedStudent['full_name'])) ?></strong></div>
                        <div><span>Admission no.</span><strong><?= e((string) ($selectedReportCard['admission_number'] ?? '—')) ?></strong></div>
                        <div><span>Class</span><strong><?= e(school_class_label(['level' => $selectedReportCard['class_level'], 'section' => $selectedReportCard['class_section']])) ?></strong></div>
                        <div><span>Academic year</span><strong><?= e((string) $selectedReportCard['academic_year']) ?> Academic Year</strong></div>
                        <div><span>Current term</span><strong><?= e((string) $selectedReportCard['term_name']) ?></strong></div>
                        <div><span>Report revision</span><strong><?= (int) ($selectedReportCard['revision_number'] ?? 1) ?></strong></div>
                        <div><span>Term closing date</span><strong><?= e($termClosingDate) ?></strong></div>
                        <div><span>Next term reopening</span><strong><?= e($nextTermReopening) ?></strong></div>
                        <div><span>Total marks obtained</span><strong><?= $rankedStudent ? e(number_format((float) $rankedStudent['total'], 2)) : '—' ?></strong></div>
                        <div><span>Class position</span><strong><?= $rankedStudent && $rankedStudent['position'] !== null ? (int) $rankedStudent['position'] : 'Pending' ?></strong></div>
                    </div>
                </div>

                <?php if (($selectedReportCard['status'] ?? 'published') !== 'published'): ?><p class="notice">Preview only — this report card has not been released.</p><?php endif; ?>
                <div class="attendance-tools"><button class="button" type="button" data-print-button>Print / Save as PDF</button><a class="button" href="report_cards.php?student_id=<?= (int) $studentId ?>&amp;batch_id=<?= (int) $selectedReportCard['id'] ?>&amp;download=html">Download HTML report card</a></div>

                <div class="table-scroll">
                    <table class="terminal-mark-table">
                        <thead>
                            <tr>
                                            <th>SUBJECT</th>
                                            <th>CLASS (30%)</th>
                                            <th>EXAMS (70%)</th>
                                            <th>TOTAL (100%)</th>
                                            <th>GRADE</th>
                                            <th>POSITION</th>
                                            <th>REMARKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportSubjectRows as $row): $subjectScore = (float) ($row['score'] ?? 0); $subjectGrade = (string) ($row['grade'] ?? ''); $subjectRemark = (string) ($row['remark'] ?? ''); if ($subjectRemark === '') { foreach (($selectedReportCard['grading_scale'] ?? $gradeRules) as $rule) { if ((string) $rule['grade'] === $subjectGrade) { $subjectRemark = (string) ($rule['remark'] ?? ''); break; } } } ?>
                                <tr>
                                    <td><?= e((string) $row['subject_name']) ?><?= $row['code'] ? ' (' . e((string) $row['code']) . ')' : '' ?></td>
                                    <td><?= ($row['class_score'] ?? null) === null ? '—' : e(number_format((float) $row['class_score'], 2) . ' / ' . number_format((float) ($row['class_score_maximum'] ?? 0), 2)) ?></td>
                                    <td><?= e(number_format((float) ($row['exam_score'] ?? $subjectScore), 2) . ' / ' . number_format((float) ($row['exam_score_maximum'] ?? $row['total_marks']), 2)) ?></td>
                                    <td><?= e(number_format($subjectScore, 2) . ' / ' . number_format((float) $row['total_marks'], 2)) ?></td>
                                    <td><?= e($subjectGrade !== '' ? $subjectGrade : '—') ?></td>
                                    <td><?= e(school_rank_position_label(isset($row['position']) ? (int) $row['position'] : null)) ?></td>
                                    <td><?= e($subjectRemark) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="terminal-summary-strip">
                    <span><strong>Attendance:</strong> <?= $reportAttendance && isset($reportAttendance['scheduled_days'])
                        ? (empty($reportAttendance['incomplete'])
                            ? (int) $reportAttendance['present'] . ' days present out of ' . (int) $reportAttendance['scheduled_days'] . ' school days'
                                . ($reportAttendance['percentage'] !== null ? ' · ' . number_format((float) $reportAttendance['percentage'], 2) . '%' : '')
                            : 'Provisional · ' . (int) $reportAttendance['recorded_days'] . ' of ' . (int) $reportAttendance['scheduled_days'] . ' school days recorded')
                        : 'Not recorded' ?></span>
                    <span><strong>Promotion:</strong> Not recorded</span>
                    <span><strong>Conduct:</strong> Not recorded</span>
                    <span><strong>Interest:</strong> Not recorded</span>
                </div>

                <div class="terminal-comments-grid">
                    <div>
                        <h4>Class teacher’s remarks</h4>
                        <p><?= nl2br(e((string) ($selectedReportCard['notes']['teacher_comment'] ?? '—'))) ?></p>
                    </div>
                    <div>
                        <h4>Headmaster’s remarks</h4>
                        <p><?= nl2br(e((string) ($selectedReportCard['notes']['headmaster_remarks'] ?? '—'))) ?></p>
                    </div>
                </div>

                <div class="terminal-signatures">
                    <div><span>Proprietor/Director</span><div class="signature-box"></div></div>
                    <div><span>Headmaster</span><div class="signature-box"></div></div>
                    <div><span>Class Teacher</span><div class="signature-box"></div></div>
                    <div><span>School Stamp</span><div class="signature-box"></div></div>
                </div>

            </section>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
