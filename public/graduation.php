<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/student_class_history.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$role = school_user_role_for_school((int) $user['id'], $schoolId);
if (!in_array($role, ['school_headmaster', 'school_proprietor'], true)) {
    http_response_code(403);
    exit('Only an authorized Headmaster or Proprietor can approve graduation.');
}
require_school_permission($schoolId, 'students.lifecycle.manage');

$readiness = static function (int $studentId, int $classId) use ($schoolId): array {
    $academicStmt = db()->prepare(
        'SELECT COUNT(DISTINCT sa.id) AS assessment_count,
                COUNT(DISTINCT CASE WHEN ar.id IS NOT NULL THEN sa.id END) AS result_count,
                COUNT(DISTINCT CASE WHEN ar.id IS NOT NULL AND sa.published_at IS NOT NULL THEN sa.id END) AS published_count
         FROM school_assessments sa
         LEFT JOIN assessment_results ar
           ON ar.assessment_id = sa.id AND ar.student_id = ? AND ar.school_id = sa.school_id
         WHERE sa.school_id = ? AND sa.class_id = ?'
    );
    $academicStmt->execute([$studentId, $schoolId, $classId]);
    $academic = $academicStmt->fetch() ?: ['assessment_count' => 0, 'result_count' => 0, 'published_count' => 0];
    $feeStmt = db()->prepare(
        'SELECT COUNT(*) AS invoice_count,
                SUM(CASE WHEN i.balance > 0 AND NOT EXISTS (
                    SELECT 1 FROM school_invoice_adjustments adj
                    WHERE adj.school_id = i.school_id AND adj.invoice_id = i.id
                      AND adj.adjustment_type = "payment_arrangement" AND adj.status = "approved"
                      AND (adj.valid_until IS NULL OR adj.valid_until >= CURRENT_DATE())
                ) THEN 1 ELSE 0 END) AS unresolved_invoice_count
         FROM school_invoices i
         WHERE i.school_id = ? AND i.student_id = ? AND i.status <> "draft"'
    );
    $feeStmt->execute([$schoolId, $studentId]);
    $fees = $feeStmt->fetch() ?: ['invoice_count' => 0, 'unresolved_invoice_count' => 0];
    return [
        'assessment_count' => (int) $academic['assessment_count'],
        'result_count' => (int) $academic['result_count'],
        'published_count' => (int) $academic['published_count'],
        'invoice_count' => (int) $fees['invoice_count'],
        'unresolved_invoice_count' => (int) ($fees['unresolved_invoice_count'] ?? 0),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
    $graduationDate = trim((string) ($_POST['graduation_date'] ?? ''));
    $clearanceNotes = trim((string) ($_POST['clearance_notes'] ?? ''));
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $graduationDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($studentId === false || $studentId < 1
        || ($_POST['results_reviewed'] ?? '') !== '1'
        || ($_POST['clearance_reviewed'] ?? '') !== '1'
        || $parsedDate === false || $parsedDate->format('Y-m-d') !== $graduationDate
        || $graduationDate > gmdate('Y-m-d')
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || preg_match('/^.{5,1000}$/us', $clearanceNotes) !== 1) {
        flash('error', 'Confirm the final-results and clearance reviews, enter a valid graduation date, and provide clearance notes.');
        redirect('graduation.php');
    }

    db()->beginTransaction();
    try {
        $studentStmt = db()->prepare(
            'SELECT ss.id, ss.full_name, ss.school_status, ss.class_id,
                    sc.level, sc.academic_year, class_level.is_graduation_level
             FROM school_students ss
             JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
             JOIN school_class_levels class_level
               ON class_level.id = sc.class_level_id AND class_level.school_id = sc.school_id
             WHERE ss.id = ? AND ss.school_id = ?
               AND sc.status = "active" AND class_level.status = "active"
             FOR UPDATE'
        );
        $studentStmt->execute([$studentId, $schoolId]);
        $student = $studentStmt->fetch();
        if (!$student || $student['school_status'] !== 'active'
            || (int) $student['is_graduation_level'] !== 1) {
            throw new RuntimeException('Only an active student in a designated graduation level can be considered for graduation.');
        }
        $checks = $readiness((int) $student['id'], (int) $student['class_id']);
        if ($checks['assessment_count'] < 1
            || $checks['result_count'] !== $checks['assessment_count']
            || $checks['published_count'] !== $checks['assessment_count']) {
            throw new RuntimeException('Graduation is blocked until every assessment for this student’s designated graduation class has a recorded and published result.');
        }
        if ($checks['unresolved_invoice_count'] > 0) {
            throw new RuntimeException('Graduation is blocked while invoices remain outstanding without an approved payment arrangement.');
        }
        $duplicateStmt = db()->prepare(
            'SELECT id FROM student_graduations
             WHERE school_id = ? AND student_id = ? AND status = "approved" FOR UPDATE'
        );
        $duplicateStmt->execute([$schoolId, $studentId]);
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('This student already has a graduation record. Review the existing record instead of creating another.');
        }

        $graduationStmt = db()->prepare(
            'INSERT INTO student_graduations
             (school_id, student_id, academic_year, completion_program, graduation_date, final_results_reviewed,
              clearance_reviewed, clearance_notes, approved_by)
             VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?)'
        );
        $graduationStmt->execute([
            $schoolId, $studentId, (string) $student['academic_year'], (string) $student['level'], $graduationDate,
            $clearanceNotes, (int) $user['id'],
        ]);
        $graduationId = (int) db()->lastInsertId();
        $lifecycleStmt = db()->prepare(
            'INSERT INTO student_lifecycle_events
             (school_id, student_id, event_type, previous_status, new_status,
              previous_class_id, new_class_id, exit_date, effective_on, reason, actor_user_id)
             VALUES (?, ?, "exit", "active", "graduated", ?, NULL, ?, ?, ?, ?)'
        );
        $lifecycleStmt->execute([
            $schoolId, $studentId, (int) $student['class_id'], $graduationDate, $graduationDate,
            'Graduation approved after final-results and clearance review: ' . $clearanceNotes,
            (int) $user['id'],
        ]);
        student_class_history_record(
            db(),
            $schoolId,
            $studentId,
            'class_change',
            (int) $student['class_id'],
            null,
            $graduationDate,
            'Student graduated from ' . (string) $student['level'] . '; academic class placement ended.',
            (int) $user['id']
        );
        $statusStmt = db()->prepare(
            'UPDATE school_students SET school_status = "graduated", class_id = NULL
             WHERE id = ? AND school_id = ? AND school_status = "active"'
        );
        $statusStmt->execute([$studentId, $schoolId]);
        if ($statusStmt->rowCount() !== 1) {
            throw new RuntimeException('The student status changed during approval. Refresh and review the record again.');
        }
        $eventStmt = db()->prepare(
            'INSERT INTO student_graduation_events
             (school_id, student_id, graduation_id, actor_user_id, event_type, reason)
             VALUES (?, ?, ?, ?, "graduation_approved", ?)'
        );
        $eventStmt->execute([$schoolId, $studentId, $graduationId, (int) $user['id'], $clearanceNotes]);
        audit((int) $user['id'], 'student_graduation_approved',
            'school_id=' . $schoolId . ';student_id=' . $studentId . ';graduation_id=' . $graduationId
                . ';academic_year=' . (string) $student['academic_year']);
        db()->commit();
        flash('success', 'Graduation approved. The student is now archived as a graduate; their historical records remain preserved.');
    } catch (RuntimeException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
    redirect('graduation.php');
}

$candidateStmt = db()->prepare(
    'SELECT ss.id, ss.full_name, ss.admission_number, ss.class_id,
            sc.academic_year, sc.section, class_level.name AS level_name
     FROM school_students ss
     JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
     JOIN school_class_levels class_level
       ON class_level.id = sc.class_level_id AND class_level.school_id = sc.school_id
     WHERE ss.school_id = ? AND ss.school_status = "active"
       AND sc.status = "active" AND class_level.status = "active"
       AND class_level.is_graduation_level = 1
     ORDER BY sc.academic_year DESC, class_level.display_order, ss.full_name'
);
$candidateStmt->execute([$schoolId]);
$candidates = $candidateStmt->fetchAll();
$candidateChecks = [];
$graduationReadinessChart = ['Ready for review' => 0, 'Outstanding results/reviews' => 0];
foreach ($candidates as $candidate) {
    $checks = $readiness((int) $candidate['id'], (int) $candidate['class_id']);
    $candidateChecks[(int) $candidate['id']] = $checks;
    $academicReady = $checks['assessment_count'] > 0
        && $checks['result_count'] === $checks['assessment_count']
        && $checks['published_count'] === $checks['assessment_count'];
    $clearanceReady = $checks['unresolved_invoice_count'] === 0;
    $status = $academicReady && $clearanceReady
        ? 'Ready for review'
        : 'Outstanding results/reviews';
    $graduationReadinessChart[$status]++;
}

$graduatesStmt = db()->prepare(
    'SELECT g.id, g.student_id, g.academic_year, g.graduation_date,
            ss.full_name, ss.admission_number,
            (SELECT c.id FROM school_completion_certificates c
             WHERE c.graduation_id = g.id AND c.status = "issued"
             ORDER BY c.issued_at DESC LIMIT 1) AS active_certificate_id
     FROM student_graduations g
     JOIN school_students ss ON ss.id = g.student_id AND ss.school_id = g.school_id
     WHERE g.school_id = ? AND g.status = "approved"
     ORDER BY g.graduation_date DESC, ss.full_name'
);
$graduatesStmt->execute([$schoolId]);
$graduates = $graduatesStmt->fetchAll();

render_header('Graduation approvals');
?>
<section class="panel">
    <p class="eyebrow">STUDENT RECORDS</p>
    <h1>Graduation approval</h1>
    <p class="muted">Graduation is never automatic. Approve only after every final assessment is recorded and published, outstanding invoices are cleared or covered by an approved arrangement, and both reviews are complete.</p>
</section>

<section class="panel spaced-top" aria-label="Graduation readiness">
    <h2>Graduation readiness</h2>
    <p class="muted">Active students in levels designated for graduation review are ready only when every assessment result is published and no invoice remains unresolved without an approved payment arrangement.</p>
    <?php render_dashboard_bar_chart('Active students by graduation readiness', $graduationReadinessChart, 'students'); ?>
</section>

<section class="panel panel-top-gap">
    <h2>Students in designated graduation levels</h2>
    <?php if ($candidates === []): ?><p class="muted">There are no active students in a designated graduation level awaiting review. Mark the appropriate terminal class level in Classes &amp; Levels.</p>
    <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Student</th><th>Class level</th><th>Academic year</th><th>Final assessments</th><th>Clearance</th><th>Approve graduation</th></tr></thead>
        <tbody><?php foreach ($candidates as $candidate): ?>
            <?php $checks = $candidateChecks[(int) $candidate['id']]; ?>
            <?php
            $academicReady = $checks['assessment_count'] > 0
                && $checks['result_count'] === $checks['assessment_count']
                && $checks['published_count'] === $checks['assessment_count'];
            $clearanceReady = $checks['unresolved_invoice_count'] === 0;
            ?>
            <tr>
                <td><strong><?= e((string) $candidate['full_name']) ?></strong><br><small>Student ID: <?= e((string) ($candidate['admission_number'] ?: $candidate['id'])) ?></small></td>
                <td><?= e((string) $candidate['level_name']) ?></td>
                <td><?= e(school_academic_year_label((string) $candidate['academic_year'])) ?></td>
                <td><?= $checks['published_count'] ?> / <?= $checks['assessment_count'] ?> published; <?= $checks['result_count'] ?> recorded
                    <br><strong><?= $academicReady ? 'Ready for review' : 'Outstanding results/reviews' ?></strong></td>
                <td>
                    <?php if ($checks['invoice_count'] === 0): ?>
                        No billing records; manual financial clearance review is required.
                    <?php elseif ($checks['unresolved_invoice_count'] === 0): ?>
                        No outstanding invoices.
                    <?php else: ?>
                        <?= $checks['unresolved_invoice_count'] ?> invoice(s) need clearance.
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$academicReady || !$clearanceReady): ?>
                        <span class="muted">Resolve the displayed blockers before approval.</span>
                    <?php else: ?>
                    <form method="post" class="form-stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="student_id" value="<?= (int) $candidate['id'] ?>">
                        <label for="grad-date-<?= (int) $candidate['id'] ?>">Graduation date</label>
                        <input id="grad-date-<?= (int) $candidate['id'] ?>" type="date" name="graduation_date" max="<?= e(gmdate('Y-m-d')) ?>" value="<?= e(gmdate('Y-m-d')) ?>" required>
                        <label class="check-label"><input type="checkbox" name="results_reviewed" value="1" required> I reviewed the final academic results</label>
                        <label class="check-label"><input type="checkbox" name="clearance_reviewed" value="1" required> I completed the financial clearance review, including manual review if billing records are missing</label>
                        <label for="clearance-notes-<?= (int) $candidate['id'] ?>">Clearance review notes</label>
                        <textarea id="clearance-notes-<?= (int) $candidate['id'] ?>" name="clearance_notes" minlength="5" maxlength="1000" required></textarea>
                        <button class="button button-primary" type="submit" onclick="return confirm('Approve graduation and archive this student?')">Approve graduation</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</section>

<section class="panel panel-top-gap">
    <h2>Approved graduates</h2>
    <?php if ($graduates === []): ?><p class="muted">No graduations have been approved yet.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Graduate</th><th>Academic year</th><th>Completion date</th><th>Certificate</th></tr></thead><tbody>
        <?php foreach ($graduates as $graduate): ?><tr>
            <td><?= e((string) $graduate['full_name']) ?><br><small><?= e((string) ($graduate['admission_number'] ?: $graduate['student_id'])) ?></small></td>
            <td><?= e(school_academic_year_label((string) $graduate['academic_year'])) ?></td>
            <td><?= e((string) $graduate['graduation_date']) ?></td>
            <td><a class="button button-secondary" href="graduation_certificates.php">Manage certificates</a></td>
        </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
